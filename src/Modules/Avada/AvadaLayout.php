<?php
/**
 * Tree operations on a parsed Avada Builder layout.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Avada;

use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\WpBakery\Layout;
use Kuduy\Mcp\Modules\WpBakery\Shortcodes;

/**
 * Avada's structure: container > row > column > elements; nested columns are an inner row with inner columns inside
 * a column. Ids are positions as in the WPBakery tools ("0" container, "0.0" its row, "0.0.1" second column,
 * "0.0.1.2" third element) and change after every edit. Values are shown decoded and written the way Avada stores
 * them; attributes nobody asked to change keep their exact text.
 */
final class AvadaLayout {

	public const CONTAINER = 'fusion_builder_container';

	/**
	 * Row tags and the column tag each holds.
	 */
	public const ROWS = array(
		'fusion_builder_row'       => 'fusion_builder_column',
		'fusion_builder_row_inner' => 'fusion_builder_column_inner',
	);

	/**
	 * Column tags.
	 */
	public const COLUMNS = array( 'fusion_builder_column', 'fusion_builder_column_inner' );

	/**
	 * Tags allowed at the top level besides containers.
	 */
	private const TOP = array( 'fusion_builder_next_page', 'fusion_builder_blank_page', 'fusion_builder_form_step', 'fusion_global' );

	/**
	 * Column widths Avada offers.
	 */
	private const WIDTHS = array( '1_1', '1_2', '1_3', '2_3', '1_4', '3_4', '1_5', '2_5', '3_5', '4_5', '1_6', '5_6', 'auto' );

	/**
	 * Attributes that hold base64-encoded JSON.
	 */
	private const BASE64_JSON = array( 'logics', 'dynamic_params', 'track_tags', 'alternate_post_cards' );

	/**
	 * Screen-size suffixes of responsive attributes.
	 */
	public const SCREENS = array(
		'medium' => '_medium',
		'small'  => '_small',
	);

	/**
	 * Most elements and deepest nesting per call.
	 */
	private const MAX_ELEMENTS = 2000;
	private const MAX_DEPTH    = 12;

	/**
	 * Role of a node: container, row, column, global, element or text.
	 *
	 * @param array<string, mixed> $node Node.
	 */
	public static function role( array $node ): string {
		if ( 'text' === $node['type'] ) {
			return 'text';
		}
		return match ( true ) {
			self::CONTAINER === $node['tag']             => 'container',
			isset( self::ROWS[ $node['tag'] ] )          => 'row',
			in_array( $node['tag'], self::COLUMNS, true ) => 'column',
			'fusion_global' === $node['tag']             => 'global',
			default                                      => 'element',
		};
	}

	/**
	 * Whether a node counts for ids (elements and text that is not just whitespace).
	 *
	 * @param array<string, mixed> $node Node.
	 */
	private static function counts( array $node ): bool {
		return 'element' === $node['type'] || '' !== trim( $node['raw'] );
	}

	/**
	 * A plain value checked for what Avada's editor cannot keep. Avada writes attribute values back raw when its own
	 * editor saves a page, so a double quote or square bracket in a setting would break the shortcode on that save
	 * (seen on Avada 7.16.4 / Builder 3.16.4, 2026-11-14: "&quot;" became a raw quote). Such values are refused.
	 *
	 * @param string $value Plain value.
	 * @param string $name  Attribute (for the message).
	 * @throws ToolError When the value has a double quote or square bracket.
	 */
	public static function encode( string $value, string $name = '' ): string {
		if ( false !== strpbrk( $value, '"[]' ) ) {
			throw new ToolError( 'Setting "' . esc_html( $name ) . '" cannot contain double quotes or square brackets: Avada\'s own editor writes them back unescaped, which breaks the page the next time it is saved there. Use single quotes or typographic quotes (“ ”), or put the text in the element\'s content. Nothing was changed.' );
		}
		return $value;
	}

	/**
	 * A stored value shown to the client.
	 *
	 * @param string $name   Attribute.
	 * @param string $stored Stored value.
	 * @return mixed
	 */
	public static function plain( string $name, string $stored ) {
		if ( in_array( $name, self::BASE64_JSON, true ) && '' !== $stored ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Avada stores these settings as base64 JSON.
			$json = json_decode( (string) base64_decode( $stored, true ), true );
			if ( is_array( $json ) ) {
				return $json;
			}
		}
		return str_replace( array( '&quot;', '&#91;', '&#93;' ), array( '"', '[', ']' ), $stored );
	}

	/**
	 * A value from tool input as Avada stores it.
	 *
	 * @param string $name  Attribute.
	 * @param mixed  $value Text, number, true/false (yes/no), a list (comma-joined) or, for base64 JSON settings, an object or list.
	 * @throws ToolError When the value has no stored form.
	 */
	public static function stored( string $name, $value ): string {
		if ( in_array( $name, self::BASE64_JSON, true ) && is_array( $value ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Avada's storage format.
			return base64_encode( (string) wp_json_encode( $value ) );
		}
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}
		if ( is_scalar( $value ) ) {
			return self::encode( (string) $value, $name );
		}
		if ( is_array( $value ) && array_is_list( $value ) && count( array_filter( $value, 'is_scalar' ) ) === count( $value ) ) {
			return self::encode( implode( ',', array_map( 'strval', $value ) ), $name );
		}
		throw new ToolError( 'Setting "' . esc_html( $name ) . '" must be text, a number, true/false or a list of values.' );
	}

	/**
	 * Attributes of an element: name => shown value (the first of duplicated names), with responsive values grouped.
	 *
	 * @param array<string, mixed> $node Element node.
	 * @return array{attrs: array<string, mixed>, responsive: array<string, array<string, mixed>>}
	 */
	public static function attrs( array $node ): array {
		$attrs      = array();
		$responsive = array();
		foreach ( $node['atts'] as $i => $att ) {
			$name = $att['name'] ?? '#' . $i;
			if ( array_key_exists( $name, $attrs ) ) {
				continue;
			}
			$value = null === $att['name'] ? $att['value'] : self::plain( $name, $att['value'] );
			foreach ( self::SCREENS as $screen => $suffix ) {
				if ( str_ends_with( $name, $suffix ) && strlen( $name ) > strlen( $suffix ) ) {
					$responsive[ $screen ][ substr( $name, 0, -strlen( $suffix ) ) ] = $value;
					continue 2;
				}
			}
			$attrs[ $name ] = $value;
		}
		return array(
			'attrs'      => $attrs,
			'responsive' => $responsive,
		);
	}

	/**
	 * Width of a column (type), or 1_1.
	 *
	 * @param array<string, mixed> $node Column node.
	 */
	public static function width( array $node ): string {
		return (string) ( Shortcodes::att( $node, 'type' ) ?? Shortcodes::att( $node, 'layout' ) ?? '1_1' );
	}

	/**
	 * A checked column width.
	 *
	 * @param string $width Width like 1_2 or 1/2.
	 * @throws ToolError When Avada has no such width.
	 */
	public static function check_width( string $width ): string {
		$width = str_replace( '/', '_', trim( $width ) );
		if ( ! in_array( $width, self::WIDTHS, true ) ) {
			throw new ToolError( 'Avada column widths are ' . esc_html( implode( ', ', self::WIDTHS ) ) . ' (1/2 works too). "' . esc_html( $width ) . '" is not one.' );
		}
		return $width;
	}

	/**
	 * Widths from a layout like "1_3 + 2_3".
	 *
	 * @param string $layout Layout.
	 * @return list<string>
	 * @throws ToolError When a width is unknown.
	 */
	public static function widths( string $layout ): array {
		return array_map( array( self::class, 'check_width' ), array_map( 'trim', explode( '+', $layout ) ) );
	}

	/**
	 * Stored attributes from tool input: attrs (null removes), responsive (screen => attrs) and width.
	 *
	 * @param array<string, mixed> $input attrs, responsive, width.
	 * @return array<string, string|null>
	 * @throws ToolError When a name or value is not allowed.
	 */
	public static function atts_from_input( array $input ): array {
		$atts = array();
		$add  = static function ( string $name, $value ) use ( &$atts ): void {
			if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/', $name ) ) {
				throw new ToolError( 'Setting names use letters, digits, _ and -. "' . esc_html( $name ) . '" is not allowed.' );
			}
			if ( 'element_content' === $name ) {
				throw new ToolError( 'The text of an element is given as content, next to attrs, not as a setting.' );
			}
			$atts[ $name ] = null === $value ? null : self::stored( $name, $value );
		};
		foreach ( (array) ( $input['attrs'] ?? array() ) as $name => $value ) {
			$add( (string) $name, $value );
		}
		foreach ( (array) ( $input['responsive'] ?? array() ) as $screen => $values ) {
			if ( ! isset( self::SCREENS[ $screen ] ) || ! is_array( $values ) ) {
				throw new ToolError( 'responsive takes the screen sizes medium and small, each with settings, e.g. {"small": {"margin_bottom": "20px"}}.' );
			}
			foreach ( $values as $name => $value ) {
				$add( $name . self::SCREENS[ $screen ], 'type' === $name && null !== $value ? self::check_width( (string) $value ) : $value );
			}
		}
		if ( isset( $input['width'] ) ) {
			$width          = self::check_width( (string) $input['width'] );
			$atts['type']   = $width;
			$atts['layout'] = $width;
		}
		return $atts;
	}

	/**
	 * Update attributes of a node; a name written twice (as Avada sometimes does) is changed everywhere.
	 *
	 * @param array<string, mixed>       $node Element node.
	 * @param array<string, string|null> $atts Changes.
	 * @return array<string, mixed>
	 */
	public static function with_atts( array $node, array $atts ): array {
		return Shortcodes::with_atts( $node, $atts );
	}

	/**
	 * Children for new content of an element (text or nested shortcodes).
	 *
	 * @param string $content Content.
	 * @return list<array<string, mixed>>
	 */
	public static function content_nodes( string $content ): array {
		return '' === $content ? array() : Avada::parse( $content );
	}

	/**
	 * A node for an element given as JSON: tag, attrs, responsive, content or children.
	 *
	 * @param mixed $input Element.
	 * @param int   $depth Depth.
	 * @param int   $count Elements so far.
	 * @return array<string, mixed>
	 * @throws ToolError When malformed.
	 */
	public static function element( $input, int $depth = 0, int &$count = 0 ): array {
		if ( ! is_array( $input ) || ! is_string( $input['tag'] ?? null ) || ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $input['tag'] ) ) {
			throw new ToolError( 'Every element needs a tag such as "fusion_title" (see kuduy-avada-elements).' );
		}
		if ( $depth > self::MAX_DEPTH || ++$count > self::MAX_ELEMENTS ) {
			throw new ToolError( 'This layout is too deep or too large (at most ' . (int) self::MAX_DEPTH . ' levels and ' . (int) self::MAX_ELEMENTS . ' elements per call).' );
		}
		$tag = $input['tag'];
		if ( isset( $input['content'] ) && ! empty( $input['children'] ) ) {
			throw new ToolError( 'Give an element either content or children, not both. Element: ' . esc_html( $tag ) . '.' );
		}
		$children = array();
		foreach ( (array) ( $input['children'] ?? array() ) as $child ) {
			$children[] = self::element( $child, $depth + 1, $count );
		}
		if ( isset( $input['content'] ) ) {
			$children = self::content_nodes( (string) $input['content'] );
		}
		$atts = array_filter( self::atts_from_input( $input ), static fn( $v ) => null !== $v );
		// Avada writes a closing tag for elements that have content or children (and for all of its layout tags);
		// a global element reference is a single tag.
		$closed = 'fusion_global' !== $tag && ( $children || isset( self::ROWS[ $tag ] ) || in_array( $tag, self::COLUMNS, true ) || self::CONTAINER === $tag || isset( Avada::params( $tag )['element_content'] ) || isset( Avada::module( $tag )['element_child'] ) );
		return Shortcodes::element( $tag, $atts, $children, $closed );
	}

	/**
	 * A column node with its elements.
	 *
	 * @param array<string, mixed> $input width, attrs, responsive, elements.
	 * @param bool                 $inner Inner column.
	 * @param int                  $count Elements so far.
	 * @return array<string, mixed>
	 * @throws ToolError When an element is malformed.
	 */
	public static function column( array $input, bool $inner, int &$count = 0 ): array {
		$elements = array();
		foreach ( (array) ( $input['elements'] ?? array() ) as $element ) {
			$node = self::element( $element, 3, $count );
			if ( self::CONTAINER === $node['tag'] || 'fusion_builder_row' === $node['tag'] ) {
				throw new ToolError( 'A container or row cannot go inside a column. For columns inside a column use kuduy-avada-add-column with the column as parent (nested columns).' );
			}
			$elements[] = $node;
		}
		$input['width'] = $input['width'] ?? '1_1';
		$atts           = array_filter( self::atts_from_input( $input ), static fn( $v ) => null !== $v );
		return Shortcodes::element( $inner ? 'fusion_builder_column_inner' : 'fusion_builder_column', $atts, $elements, true );
	}

	/**
	 * Column nodes from a layout or a column list.
	 *
	 * @param array<string, mixed> $input layout or columns.
	 * @param bool                 $inner Inner columns.
	 * @return list<array<string, mixed>>
	 * @throws ToolError When malformed.
	 */
	public static function columns( array $input, bool $inner ): array {
		$columns = ! empty( $input['columns'] ) && is_array( $input['columns'] ) ? array_values( $input['columns'] ) : array_map( static fn( string $w ) => array( 'width' => $w ), self::widths( (string) ( $input['layout'] ?? '1_1' ) ) );
		if ( count( $columns ) > 12 ) {
			throw new ToolError( 'A row holds at most 12 columns here.' );
		}
		$count = 0;
		$out   = array();
		foreach ( $columns as $column ) {
			if ( ! is_array( $column ) ) {
				throw new ToolError( 'Each column is an object: width, and optionally attrs, responsive, elements.' );
			}
			$out[] = self::column( $column, $inner, $count );
		}
		return $out;
	}

	/**
	 * A container with its row and columns.
	 *
	 * @param array<string, mixed> $input layout or columns, attrs, responsive.
	 * @return array<string, mixed>
	 * @throws ToolError When malformed.
	 */
	public static function container( array $input ): array {
		$row  = Shortcodes::element( 'fusion_builder_row', array(), self::columns( $input, false ), true );
		$atts = array_filter( self::atts_from_input( array_diff_key( $input, array( 'width' => 1 ) ) ), static fn( $v ) => null !== $v );
		return Shortcodes::element( self::CONTAINER, $atts, array( $row ), true );
	}

	/**
	 * An inner row with inner columns (nested columns).
	 *
	 * @param array<string, mixed> $input layout or columns.
	 * @return array<string, mixed>
	 * @throws ToolError When malformed.
	 */
	public static function inner_row( array $input ): array {
		return Shortcodes::element( 'fusion_builder_row_inner', array(), self::columns( $input, true ), true );
	}

	/**
	 * Text of an element for the outline: its content, or its most telling setting.
	 *
	 * @param array<string, mixed> $node Element node.
	 */
	private static function hint( array $node ): string {
		$text = trim( wp_strip_all_tags( Shortcodes::serialize( $node['children'] ) ) );
		if ( '' !== $text ) {
			return $text;
		}
		foreach ( array( 'title', 'text', 'link', 'image_id', 'id', 'admin_label' ) as $name ) {
			$value = Shortcodes::att( $node, $name );
			if ( null !== $value && '' !== $value ) {
				return $name . '=' . self::plain( $name, $value );
			}
		}
		return '';
	}

	/**
	 * One short line.
	 *
	 * @param string $text Text.
	 */
	private static function excerpt( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		return mb_strlen( $text ) > 60 ? mb_substr( $text, 0, 57 ) . '...' : $text;
	}

	/**
	 * Compact outline: one line per container, row, column and element.
	 *
	 * @param list<array<string, mixed>> $nodes Nodes.
	 * @param string                     $into  Parent id.
	 * @param int                        $depth Depth.
	 * @return list<array<string, mixed>>
	 */
	public static function outline( array $nodes, string $into = '', int $depth = 0 ): array {
		$out   = array();
		$index = 0;
		foreach ( $nodes as $node ) {
			if ( ! self::counts( $node ) ) {
				continue;
			}
			$id = Layout::child_id( $into, $index++ );
			if ( 'text' === $node['type'] ) {
				$out[] = array(
					'id'    => $id,
					'depth' => $depth,
					'type'  => 'text',
					'label' => 'Text: ' . self::excerpt( $node['raw'] ),
				);
				continue;
			}
			$role  = self::role( $node );
			$name  = (string) ( Avada::module( $node['tag'] )['name'] ?? $node['tag'] );
			$admin = Shortcodes::att( $node, 'admin_label' );
			$label = match ( $role ) {
				'container' => 'Container' . ( null !== $admin && '' !== $admin ? ' "' . self::plain( 'admin_label', $admin ) . '"' : '' ),
				'row'       => 'fusion_builder_row' === $node['tag'] ? 'Row' : 'Nested columns',
				'column'    => 'Column (' . str_replace( '_', '/', self::width( $node ) ) . ')',
				'global'    => 'Global element #' . (string) Shortcodes::att( $node, 'id' ),
				default     => $name,
			};
			$nested = (bool) array_filter( $node['children'], static fn( array $c ) => 'element' === $c['type'] );
			if ( 'element' === $role && ! $nested ) {
				$hint = self::excerpt( self::hint( $node ) );
				if ( '' !== $hint ) {
					$label .= ': ' . $hint;
				}
			}
			$out[] = array(
				'id'    => $id,
				'depth' => $depth,
				'type'  => $role,
				'tag'   => $node['tag'],
				'label' => $label,
			);
			if ( $nested ) {
				array_push( $out, ...self::outline( $node['children'], $id, $depth + 1 ) );
			}
		}//end foreach
		return $out;
	}

	/**
	 * Full structure with settings and content.
	 *
	 * @param list<array<string, mixed>> $nodes Nodes.
	 * @param string                     $into  Parent id.
	 * @return list<array<string, mixed>>
	 */
	public static function structure( array $nodes, string $into = '' ): array {
		$out   = array();
		$index = 0;
		foreach ( $nodes as $node ) {
			if ( ! self::counts( $node ) ) {
				continue;
			}
			$id = Layout::child_id( $into, $index++ );
			if ( 'text' === $node['type'] ) {
				$out[] = array(
					'id'   => $id,
					'type' => 'text',
					'text' => $node['raw'],
				);
				continue;
			}
			$module = Avada::module( $node['tag'] );
			$split  = self::attrs( $node );
			$item   = array(
				'id'         => $id,
				'parent_id'  => $into,
				'type'       => self::role( $node ),
				'tag'        => $node['tag'],
				'title'      => (string) ( $module['name'] ?? $node['tag'] ),
				'registered' => null !== $module,
				'attrs'      => (object) $split['attrs'],
			);
			if ( $split['responsive'] ) {
				$item['responsive'] = $split['responsive'];
			}
			if ( 'column' === $item['type'] ) {
				$item['width'] = self::width( $node );
			}
			if ( 'global' === $item['type'] ) {
				$item['global_id'] = (int) Shortcodes::att( $node, 'id' );
			}
			if ( null !== $module ) {
				$unknown = array_values( array_diff( array_keys( $split['attrs'] ), array_keys( Avada::params( $node['tag'] ) ) ) );
				if ( $unknown && Avada::params( $node['tag'] ) ) {
					$item['unregistered_attrs'] = $unknown;
				}
			}
			if ( array_filter( $node['children'], static fn( array $c ) => 'element' === $c['type'] ) ) {
				$item['children'] = self::structure( $node['children'], $id );
			} elseif ( ! empty( $node['closed'] ) ) {
				$item['content'] = Shortcodes::serialize( $node['children'] );
			}
			$out[] = $item;
		}//end foreach
		return $out;
	}

	/**
	 * Count elements.
	 *
	 * @param list<array<string, mixed>> $nodes Nodes.
	 */
	public static function count( array $nodes ): int {
		return Layout::count( $nodes );
	}

	/**
	 * Check a layout: errors that would break it, warnings worth knowing.
	 *
	 * @param list<array<string, mixed>> $nodes Layout.
	 * @return array{errors: list<array<string, string>>, warnings: list<array<string, string>>}
	 */
	public static function validate( array $nodes ): array {
		$report = array(
			'errors'   => array(),
			'warnings' => array(),
		);
		self::check( $nodes, '', '', $report );
		return $report;
	}

	/**
	 * Check one level.
	 *
	 * @param list<array<string, mixed>>                                                        $nodes  Nodes.
	 * @param string                                                                            $into   Parent id.
	 * @param string                                                                            $above  Parent tag ('' = top).
	 * @param array{errors: list<array<string, string>>, warnings: list<array<string, string>>} $report Report.
	 */
	private static function check( array $nodes, string $into, string $above, array &$report ): void {
		$index = 0;
		foreach ( $nodes as $node ) {
			if ( 'text' === $node['type'] ) {
				if ( ! empty( $node['orphan'] ) && preg_match_all( '#\[/([A-Za-z0-9_-]+)\]#', $node['raw'], $m ) ) {
					$report['errors'][] = array(
						'at'      => '' === $into ? 'top level' : $into,
						'problem' => 'Closing tag without a matching opening tag: ' . implode( ', ', array_unique( $m[0] ) ) . '.',
					);
				}
				if ( self::counts( $node ) ) {
					$id = Layout::child_id( $into, $index++ );
					if ( '' === $above || self::CONTAINER === $above || isset( self::ROWS[ $above ] ) ) {
						$report['warnings'][] = array(
							'at'      => $id,
							'problem' => 'Text outside a column. Avada Builder cannot show it as an element and may wrap or drop it when the page is next saved in its editor.',
						);
					}
				}
				continue;
			}
			$id    = Layout::child_id( $into, $index++ );
			$tag   = (string) $node['tag'];
			$role  = self::role( $node );
			$error = match ( true ) {
				self::CONTAINER === $tag && '' !== $above                                              => 'A container must be at the top level of the page.',
				'fusion_builder_row' === $tag && self::CONTAINER !== $above                            => 'A row (fusion_builder_row) must sit directly inside a container.',
				'fusion_builder_row_inner' === $tag && 'fusion_builder_column' !== $above              => 'Nested columns (fusion_builder_row_inner) must sit directly inside a column.',
				'fusion_builder_column' === $tag && 'fusion_builder_row' !== $above                    => 'A column must sit directly inside a row.',
				'fusion_builder_column_inner' === $tag && 'fusion_builder_row_inner' !== $above        => 'A nested column must sit directly inside nested columns (fusion_builder_row_inner).',
				self::CONTAINER === $above && 'fusion_builder_row' !== $tag && 'global' !== $role       => 'A container holds a row, not ' . $tag . '.',
				isset( self::ROWS[ $above ] ) && self::ROWS[ $above ] !== $tag                         => 'A row may only hold ' . self::ROWS[ $above ] . ' columns, not ' . $tag . '.',
				in_array( $role, array( 'container', 'row', 'column' ), true ) && empty( $node['closed'] ) => $tag . ' has no closing tag [/' . $tag . '].',
				default => null,
			};
			if ( null !== $error ) {
				$report['errors'][] = array(
					'at'      => $id,
					'problem' => $error,
				);
			}
			if ( '' === $above && 'container' !== $role && ! in_array( $tag, self::TOP, true ) ) {
				$report['warnings'][] = array(
					'at'      => $id,
					'problem' => $tag . ' is at the top level, outside a container. Avada Builder shows such content only as plain text.',
				);
			}
			if ( 'global' === $role ) {
				$target = get_post( (int) Shortcodes::att( $node, 'id' ) );
				if ( ! $target instanceof \WP_Post || 'fusion_element' !== $target->post_type ) {
					$report['warnings'][] = array(
						'at'      => $id,
						'problem' => 'Global element #' . (string) Shortcodes::att( $node, 'id' ) . ' does not exist in the Avada Library; nothing shows here.',
					);
				}
			}
			if ( 'column' === $role ) {
				$width = self::width( $node );
				if ( ! in_array( $width, self::WIDTHS, true ) ) {
					$report['warnings'][] = array(
						'at'      => $id,
						'problem' => 'Column width "' . $width . '" is not one Avada offers.',
					);
				}
			}
			$module = Avada::module( $tag );
			if ( null === $module && ! shortcode_exists( $tag ) && ! in_array( $role, array( 'container', 'row', 'column', 'global' ), true ) ) {
				$report['warnings'][] = array(
					'at'      => $id,
					'problem' => 'Unknown element "' . $tag . '": no active plugin registers it, so it shows as plain text. It is kept as it is.',
				);
			} elseif ( null !== $module ) {
				self::check_atts( $node, $id, $report );
			}
			self::check( $node['children'], $id, $tag, $report );
		}//end foreach
	}

	/**
	 * Option values checked against the registered settings.
	 *
	 * @param array<string, mixed>                                                              $node   Element node.
	 * @param string                                                                            $id     Id.
	 * @param array{errors: list<array<string, string>>, warnings: list<array<string, string>>} $report Report.
	 */
	private static function check_atts( array $node, string $id, array &$report ): void {
		$params = Avada::params( $node['tag'] );
		foreach ( $node['atts'] as $att ) {
			$param = null === $att['name'] ? null : ( $params[ $att['name'] ] ?? null );
			if ( null === $param || ! in_array( (string) ( $param['type'] ?? '' ), array( 'select', 'radio_button_set' ), true ) || ! is_array( $param['value'] ?? null ) || ! $param['value'] ) {
				continue;
			}
			$allowed = array_map( 'strval', array_keys( $param['value'] ) );
			if ( '' !== $att['value'] && ! in_array( $att['value'], $allowed, true ) ) {
				$report['warnings'][] = array(
					'at'      => $id,
					'problem' => 'Setting "' . $att['name'] . '" of ' . $node['tag'] . ' is "' . $att['value'] . '", which is not one of its options: ' . implode( ', ', array_slice( $allowed, 0, 30 ) ) . '.',
				);
			}
		}
	}
}
