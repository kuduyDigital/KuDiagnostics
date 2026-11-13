<?php
/**
 * Tree operations on a parsed WPBakery layout: ids, reading, building, editing, validating.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\WpBakery;

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Elements have no ids in WPBakery, so the tools address them by position: "0.1.2" is the third item in the second
 * item of the first top-level item, counting elements and non-empty text (whitespace between tags is kept but not
 * counted). Ids change when the layout changes; every write returns the new tree.
 */
final class Layout {

	/**
	 * Row tags and the column tag each one holds.
	 */
	public const ROWS = array(
		'vc_row'       => 'vc_column',
		'vc_row_inner' => 'vc_column_inner',
	);

	/**
	 * Column tags.
	 */
	public const COLUMNS = array( 'vc_column', 'vc_column_inner' );

	/**
	 * Deepest nesting accepted in new layouts.
	 */
	private const MAX_DEPTH = 12;

	/**
	 * Most elements accepted in one new layout.
	 */
	private const MAX_ELEMENTS = 2000;

	/**
	 * Design option keys => CSS property.
	 */
	private const DESIGN = array(
		'margin_top'          => 'margin-top',
		'margin_right'        => 'margin-right',
		'margin_bottom'       => 'margin-bottom',
		'margin_left'         => 'margin-left',
		'border_top_width'    => 'border-top-width',
		'border_right_width'  => 'border-right-width',
		'border_bottom_width' => 'border-bottom-width',
		'border_left_width'   => 'border-left-width',
		'padding_top'         => 'padding-top',
		'padding_right'       => 'padding-right',
		'padding_bottom'      => 'padding-bottom',
		'padding_left'        => 'padding-left',
		'background_color'    => 'background-color',
		'background_image'    => 'background-image',
		'background_position' => 'background-position',
		'background_repeat'   => 'background-repeat',
		'background_size'     => 'background-size',
		'border_color'        => 'border-color',
		'border_style'        => 'border-style',
		'border_radius'       => 'border-radius',
	);

	/**
	 * Role of a node: row, column, element or text.
	 *
	 * @param array<string, mixed> $node Node.
	 */
	public static function role( array $node ): string {
		if ( 'text' === $node['type'] ) {
			return 'text';
		}
		if ( isset( self::ROWS[ $node['tag'] ] ) ) {
			return 'row';
		}
		return in_array( $node['tag'], self::COLUMNS, true ) ? 'column' : 'element';
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
	 * Real child indexes for an id.
	 *
	 * @param list<array<string, mixed>> $nodes Layout.
	 * @param string                     $id    Id like "0.1.2".
	 * @return list<int>
	 * @throws ToolError When there is no such item.
	 */
	public static function path( array $nodes, string $id ): array {
		if ( ! preg_match( '/^\d+(\.\d+)*$/', $id ) ) {
			throw new ToolError( 'Ids look like "0" or "0.1.2" (see the page tree). "' . esc_html( $id ) . '" is not one.' );
		}
		$path  = array();
		$level = $nodes;
		foreach ( array_map( 'intval', explode( '.', $id ) ) as $wanted ) {
			$seen  = -1;
			$found = null;
			foreach ( $level as $i => $node ) {
				if ( self::counts( $node ) && ++$seen === $wanted ) {
					$found = $i;
					break;
				}
			}
			if ( null === $found ) {
				throw new ToolError( 'There is no item "' . esc_html( $id ) . '" in this layout. Ids change after every edit: read the page tree again.' );
			}
			$path[] = $found;
			$level  = 'element' === $level[ $found ]['type'] ? $level[ $found ]['children'] : array();
		}
		return $path;
	}

	/**
	 * The node at a path.
	 *
	 * @param list<array<string, mixed>> $nodes Layout.
	 * @param list<int>                  $path  Path from path().
	 * @return array<string, mixed>
	 */
	public static function at( array $nodes, array $path ): array {
		$node = array( 'children' => $nodes );
		foreach ( $path as $i ) {
			$node = $node['children'][ $i ];
		}
		return $node;
	}

	/**
	 * The element with an id, checked against an expected tag or role.
	 *
	 * @param list<array<string, mixed>> $nodes  Layout.
	 * @param string                     $id     Id.
	 * @param array<string, mixed>       $expect tag (string) and/or role (row | column).
	 * @return array{0: list<int>, 1: array<string, mixed>} Path and node.
	 * @throws ToolError When the item is missing or something else than expected.
	 */
	public static function find( array $nodes, string $id, array $expect = array() ): array {
		$path = self::path( $nodes, $id );
		$node = self::at( $nodes, $path );
		$is   = 'text' === $node['type'] ? 'text' : $node['tag'];
		if ( isset( $expect['tag'] ) && '' !== $expect['tag'] && $expect['tag'] !== $is ) {
			throw new ToolError( 'Item "' . esc_html( $id ) . '" is ' . esc_html( $is ) . ', not ' . esc_html( (string) $expect['tag'] ) . '. The layout may have changed: read kuduy-wpbakery-tree again. Nothing was changed.' );
		}
		if ( isset( $expect['role'] ) && self::role( $node ) !== $expect['role'] ) {
			throw new ToolError( 'Item "' . esc_html( $id ) . '" is ' . esc_html( $is ) . ', not a ' . esc_html( (string) $expect['role'] ) . '. Nothing was changed.' );
		}
		return array( $path, $node );
	}

	/**
	 * Replace (or with null remove) the node at a path.
	 *
	 * @param list<array<string, mixed>> $nodes Layout.
	 * @param list<int>                  $path  Path.
	 * @param array<string, mixed>|null  $node  New node, or null to remove.
	 * @return list<array<string, mixed>>
	 */
	public static function put( array $nodes, array $path, ?array $node ): array {
		$i = array_shift( $path );
		if ( $path ) {
			$nodes[ $i ]['children'] = self::put( $nodes[ $i ]['children'], $path, $node );
			return $nodes;
		}
		if ( null === $node ) {
			unset( $nodes[ $i ] );
			return Shortcodes::merge_text( array_values( $nodes ) );
		}
		$nodes[ $i ] = $node;
		return $nodes;
	}

	/**
	 * Insert a node among the children of the element at $into (empty path: top level), before the counted child
	 * number $index (null or past the end: append).
	 *
	 * @param list<array<string, mixed>> $nodes  Layout.
	 * @param list<int>                  $into Parent path.
	 * @param int|null                   $index  Position among counted children.
	 * @param array<string, mixed>       $node   Node to insert.
	 * @return array{0: list<array<string, mixed>>, 1: int} New layout and the counted position used.
	 */
	public static function insert( array $nodes, array $into, ?int $index, array $node ): array {
		if ( $into ) {
			$i                           = array_shift( $into );
			$open                        = $nodes[ $i ];
			[ $open['children'], $used ] = self::insert( $open['children'], $into, $index, $node );
			if ( empty( $open['closed'] ) ) {
				// A tag written without a closing tag gets one now that it has content.
				$open['closed'] = true;
				$open['self']   = false;
				$open['open']   = null;
			}
			$nodes[ $i ] = $open;
			return array( $nodes, $used );
		}
		$seen = 0;
		$real = count( $nodes );
		foreach ( $nodes as $i => $child ) {
			if ( ! self::counts( $child ) ) {
				continue;
			}
			if ( null !== $index && $seen === $index ) {
				$real = $i;
				break;
			}
			++$seen;
		}
		array_splice( $nodes, $real, 0, array( $node ) );
		return array( $nodes, $seen );
	}

	/**
	 * Id of the counted child number $index under a parent id ('' for the top level).
	 *
	 * @param string $into Parent id.
	 * @param int    $index  Counted position.
	 */
	public static function child_id( string $into, int $index ): string {
		return '' === $into ? (string) $index : $into . '.' . $index;
	}

	/**
	 * Whether an element's content is stored base64-encoded (Raw HTML / Raw JS).
	 *
	 * @param string $tag Tag.
	 */
	private static function raw_content( string $tag ): bool {
		return in_array( $tag, WpBakery::RAW_TAGS, true ) || 'textarea_raw_html' === ( WpBakery::param( $tag, 'content' )['type'] ?? '' );
	}

	/**
	 * A stored attribute value in the shape tools show and accept.
	 *
	 * @param string $tag    Tag.
	 * @param string $name   Attribute.
	 * @param string $stored Stored value.
	 * @return mixed
	 */
	public static function plain( string $tag, string $name, string $stored ) {
		$type = (string) ( WpBakery::param( $tag, $name )['type'] ?? '' );
		if ( 'vc_link' === $type && '' !== $stored ) {
			$link = array();
			foreach ( explode( '|', $stored ) as $part ) {
				$pair = explode( ':', $part, 2 );
				if ( 2 === count( $pair ) && in_array( $pair[0], array( 'url', 'title', 'target', 'rel' ), true ) ) {
					$link[ $pair[0] ] = trim( rawurldecode( $pair[1] ) );
				}
			}
			if ( $link ) {
				return $link;
			}
		}
		if ( 'param_group' === $type && '' !== $stored ) {
			$rows = json_decode( urldecode( $stored ), true );
			if ( is_array( $rows ) ) {
				return $rows;
			}
		}
		return Shortcodes::decode( $stored );
	}

	/**
	 * A value from tool input as WPBakery stores it in an attribute.
	 *
	 * @param string $tag   Tag.
	 * @param string $name  Attribute.
	 * @param mixed  $value Text, number, boolean, a link object (url, title, target, rel), a list of ids or a list of rows.
	 * @throws ToolError When the value has no stored form.
	 */
	public static function stored( string $tag, string $name, $value ): string {
		if ( is_bool( $value ) ) {
			$options = (array) ( WpBakery::param( $tag, $name )['value'] ?? array() );
			$on      = 1 === count( $options ) && is_scalar( reset( $options ) ) ? (string) reset( $options ) : 'yes';
			return $value ? $on : '';
		}
		if ( is_scalar( $value ) ) {
			return Shortcodes::encode( (string) $value );
		}
		if ( is_array( $value ) && isset( $value['url'] ) && is_scalar( $value['url'] ) ) {
			$parts = array( 'url:' . rawurlencode( esc_url_raw( (string) $value['url'] ) ) );
			foreach ( array( 'title', 'target', 'rel' ) as $key ) {
				if ( isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) && '' !== (string) $value[ $key ] ) {
					$parts[] = $key . ':' . rawurlencode( sanitize_text_field( (string) $value[ $key ] ) );
				}
			}
			return implode( '|', $parts );
		}
		if ( is_array( $value ) && array_is_list( $value ) ) {
			if ( array_filter( $value, 'is_array' ) ) {
				return rawurlencode( (string) wp_json_encode( $value ) );
			}
			if ( count( array_filter( $value, 'is_scalar' ) ) === count( $value ) ) {
				return Shortcodes::encode( implode( ',', array_map( 'strval', $value ) ) );
			}
		}
		throw new ToolError( 'Attribute "' . esc_html( $name ) . '" must be text, a number, true/false, a link object (url, title, target, rel), a list of ids, or a list of rows for a repeating group.' );
	}

	/**
	 * Attributes of an element as name => shown value.
	 *
	 * @param array<string, mixed> $node Element node.
	 * @return array<string, mixed>
	 */
	public static function attrs( array $node ): array {
		$out = array();
		foreach ( $node['atts'] as $i => $att ) {
			$name         = $att['name'] ?? '#' . $i;
			$out[ $name ] = null === $att['name'] ? $att['value'] : self::plain( $node['tag'], $name, $att['value'] );
		}
		return $out;
	}

	/**
	 * Content of an element as shown and accepted by the tools (Raw HTML / JS decoded).
	 *
	 * @param array<string, mixed> $node Element node.
	 */
	public static function content( array $node ): string {
		$raw = Shortcodes::serialize( $node['children'] );
		if ( self::raw_content( $node['tag'] ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- WPBakery's storage format for Raw HTML / JS.
			$decoded = base64_decode( trim( wp_strip_all_tags( $raw ) ), true );
			return false === $decoded ? $raw : rawurldecode( $decoded );
		}
		return $raw;
	}

	/**
	 * Children for new content of an element.
	 *
	 * @param string $tag     Tag.
	 * @param string $content Content from tool input.
	 * @return list<array<string, mixed>>
	 */
	public static function content_nodes( string $tag, string $content ): array {
		if ( '' === $content ) {
			return array();
		}
		if ( self::raw_content( $tag ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- WPBakery's storage format for Raw HTML / JS.
			return array( Shortcodes::text( base64_encode( rawurlencode( $content ) ) ) );
		}
		return WpBakery::parse( $content );
	}

	/**
	 * Whether a new element of this tag is written with a closing tag even when empty.
	 *
	 * @param string $tag Tag.
	 */
	private static function container( string $tag ): bool {
		if ( isset( self::ROWS[ $tag ] ) || in_array( $tag, self::COLUMNS, true ) || 'vc_section' === $tag ) {
			return true;
		}
		$settings = WpBakery::settings( $tag );
		return null !== $settings && ( ! empty( $settings['is_container'] ) || ! empty( $settings['as_parent'] ) || null !== WpBakery::param( $tag, 'content' ) );
	}

	/**
	 * Full structure of a layout for kuduy-wpbakery-structure.
	 *
	 * @param list<array<string, mixed>> $nodes  Nodes.
	 * @param string                     $into Parent id.
	 * @return list<array<string, mixed>>
	 */
	public static function structure( array $nodes, string $into = '' ): array {
		$out   = array();
		$index = 0;
		foreach ( $nodes as $node ) {
			if ( ! self::counts( $node ) ) {
				continue;
			}
			$id = self::child_id( $into, $index++ );
			if ( 'text' === $node['type'] ) {
				$out[] = array(
					'id'   => $id,
					'type' => 'text',
					'text' => $node['raw'],
				);
				continue;
			}
			$settings = WpBakery::settings( $node['tag'] );
			$item     = array(
				'id'         => $id,
				'type'       => self::role( $node ),
				'tag'        => $node['tag'],
				'title'      => (string) ( $settings['name'] ?? $node['tag'] ),
				'registered' => null !== $settings,
				'attrs'      => (object) self::attrs( $node ),
			);
			if ( 'column' === $item['type'] ) {
				$item['width'] = Shortcodes::att( $node, 'width' ) ?? '1/1';
			}
			if ( array_filter( $node['children'], static fn( array $c ) => 'element' === $c['type'] ) ) {
				$item['children'] = self::structure( $node['children'], $id );
			} elseif ( ! empty( $node['closed'] ) ) {
				$item['content'] = self::content( $node );
			}
			$out[] = $item;
		}//end foreach
		return $out;
	}

	/**
	 * Compact outline of a layout: one line per item for kuduy-wpbakery-tree and write results.
	 *
	 * @param list<array<string, mixed>> $nodes  Nodes.
	 * @param string                     $into Parent id.
	 * @param int                        $depth  Depth.
	 * @return list<array<string, mixed>>
	 */
	public static function outline( array $nodes, string $into = '', int $depth = 0 ): array {
		$out   = array();
		$index = 0;
		foreach ( $nodes as $node ) {
			if ( ! self::counts( $node ) ) {
				continue;
			}
			$id = self::child_id( $into, $index++ );
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
			$title = (string) ( WpBakery::settings( $node['tag'] )['name'] ?? $node['tag'] );
			$label = match ( $role ) {
				'row'    => 'vc_row' === $node['tag'] ? 'Row' : 'Inner row',
				'column' => 'Column (' . ( Shortcodes::att( $node, 'width' ) ?? '1/1' ) . ')',
				default  => $title,
			};
			$nested = (bool) array_filter( $node['children'], static fn( array $c ) => 'element' === $c['type'] );
			if ( 'element' === $role ) {
				$hint = $nested ? '' : self::excerpt( self::hint( $node ) );
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
	 * What tells one element from another in the outline: its text, or its most telling attribute.
	 *
	 * @param array<string, mixed> $node Element node.
	 */
	private static function hint( array $node ): string {
		if ( ! in_array( $node['tag'], WpBakery::RAW_TAGS, true ) ) {
			$text = trim( wp_strip_all_tags( Shortcodes::serialize( $node['children'] ) ) );
			if ( '' !== $text ) {
				return $text;
			}
		}
		foreach ( array( 'text', 'title', 'label', 'image', 'images', 'link', 'el_id' ) as $name ) {
			$value = Shortcodes::att( $node, $name );
			if ( null !== $value && '' !== $value ) {
				return $name . '=' . Shortcodes::decode( $value );
			}
		}
		return '';
	}

	/**
	 * One short line of text.
	 *
	 * @param string $text Text.
	 */
	private static function excerpt( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
		return strlen( $text ) > 60 ? substr( $text, 0, 57 ) . '...' : $text;
	}

	/**
	 * The outline drawn as a text tree.
	 *
	 * @param list<array<string, mixed>> $outline From outline().
	 */
	public static function drawing( array $outline ): string {
		$lines = array( 'Page' );
		$count = count( $outline );
		foreach ( $outline as $i => $item ) {
			$last = true;
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( $outline[ $j ]['depth'] <= $item['depth'] ) {
					$last = $outline[ $j ]['depth'] < $item['depth'];
					break;
				}
			}
			$prefix = '';
			for ( $d = 0; $d < $item['depth']; $d++ ) {
				$open = false;
				for ( $j = $i + 1; $j < $count; $j++ ) {
					if ( $outline[ $j ]['depth'] <= $d ) {
						$open = $outline[ $j ]['depth'] === $d;
						break;
					}
				}
				$prefix .= $open ? '│   ' : '    ';
			}
			$lines[] = $prefix . ( $last ? '└── ' : '├── ' ) . $item['label'] . '  [' . $item['id'] . ']';
		}//end foreach
		return implode( "\n", $lines );
	}

	/**
	 * Column widths of a layout like "1/3 + 2/3".
	 *
	 * @param string $layout Layout.
	 * @return list<string>
	 * @throws ToolError When a width is not a fraction.
	 */
	public static function widths( string $layout ): array {
		$widths = array();
		foreach ( explode( '+', $layout ) as $part ) {
			$widths[] = self::width( trim( $part ) );
		}
		return $widths;
	}

	/**
	 * A checked column width.
	 *
	 * @param string $width Width like 1/2.
	 * @throws ToolError When it is not a fraction WPBakery knows.
	 */
	public static function width( string $width ): string {
		if ( ! preg_match( '#^(\d{1,2})/(\d{1,2})$#', $width, $m ) || ! in_array( (int) $m[2], array( 1, 2, 3, 4, 5, 6, 12 ), true ) || (int) $m[1] < 1 || (int) $m[1] > (int) $m[2] ) {
			throw new ToolError( 'Column widths are fractions of the row such as 1/1, 1/2, 1/3, 2/3, 1/4, 3/4, 1/5, 1/6, 5/12. "' . esc_html( $width ) . '" is not one.' );
		}
		return $width;
	}

	/**
	 * The offset attribute for responsive settings: a width per screen size and sizes to hide on.
	 *
	 * @param array<string, mixed> $responsive lg, md, sm, xs => width; hide => list of sizes.
	 * @throws ToolError When a size or width is unknown.
	 */
	public static function offset( array $responsive ): string {
		$classes = array();
		foreach ( $responsive as $size => $value ) {
			if ( 'hide' === $size ) {
				foreach ( (array) $value as $hidden ) {
					if ( ! in_array( $hidden, array( 'lg', 'md', 'sm', 'xs' ), true ) ) {
						throw new ToolError( 'Screen sizes are lg, md, sm and xs.' );
					}
					$classes[] = 'vc_hidden-' . $hidden;
				}
				continue;
			}
			if ( ! in_array( $size, array( 'lg', 'md', 'sm', 'xs' ), true ) ) {
				throw new ToolError( 'Screen sizes are lg, md, sm and xs.' );
			}
			$width     = self::width( (string) $value );
			[ $n, $d ] = array_map( 'intval', explode( '/', $width ) );
			$classes[] = 5 === $d ? 'vc_col-' . $size . '-' . $width : 'vc_col-' . $size . '-' . (int) round( 12 * $n / $d );
		}
		return implode( ' ', $classes );
	}

	/**
	 * The css attribute for Design Options. Starts from the element's current css, so one call can change one value.
	 *
	 * @param string|null          $current Current css attribute (".vc_custom_123{...}") or null.
	 * @param array<string, mixed> $design  Design keys => value; null or '' removes a value.
	 * @throws ToolError When a key or value is not allowed.
	 */
	public static function css( ?string $current, array $design ): string {
		$class = '';
		$rules = array();
		if ( null !== $current && preg_match( '/^\s*\.(vc_custom_\d+)\s*\{(.*)\}\s*$/s', $current, $m ) ) {
			$class = $m[1];
			foreach ( explode( ';', $m[2] ) as $rule ) {
				$pair = explode( ':', $rule, 2 );
				if ( 2 === count( $pair ) && '' !== trim( $pair[0] ) ) {
					$rules[ trim( $pair[0] ) ] = trim( $pair[1] );
				}
			}
		}
		foreach ( $design as $key => $value ) {
			$property = self::DESIGN[ $key ] ?? null;
			if ( null === $property ) {
				throw new ToolError( 'Unknown design option "' . esc_html( (string) $key ) . '". Use: ' . esc_html( implode( ', ', array_keys( self::DESIGN ) ) ) . '.' );
			}
			if ( null === $value || '' === $value ) {
				unset( $rules[ $property ] );
				continue;
			}
			$rules[ $property ] = self::css_value( (string) $key, $value ) . ' !important';
			if ( 'background_image' === $key ) {
				$rules += array(
					'background-position' => 'center !important',
					'background-repeat'   => 'no-repeat !important',
					'background-size'     => 'cover !important',
				);
			}
		}
		if ( ! $rules ) {
			return '';
		}
		if ( '' === $class ) {
			$class = 'vc_custom_' . (int) floor( microtime( true ) * 1000 ) . wp_rand( 100, 999 );
		}
		$css = '';
		foreach ( $rules as $property => $value ) {
			$css .= $property . ': ' . $value . ';';
		}
		return '.' . $class . '{' . $css . '}';
	}

	/**
	 * One checked CSS value.
	 *
	 * @param string $key   Design key.
	 * @param mixed  $value Value.
	 * @throws ToolError When the value is not allowed.
	 */
	private static function css_value( string $key, $value ): string {
		if ( 'background_image' === $key ) {
			$url = is_numeric( $value ) ? (string) wp_get_attachment_url( (int) $value ) : '';
			if ( '' === $url || ! wp_attachment_is_image( (int) $value ) ) {
				throw new ToolError( 'background_image is the id of an image in the media library (see kuduy-media-list).' );
			}
			return 'url(' . esc_url_raw( $url ) . '?id=' . (int) $value . ')';
		}
		$value = trim( (string) $value );
		$ok    = match ( true ) {
			str_ends_with( $key, '_color' )   => (bool) preg_match( '/^(#[0-9a-fA-F]{3,8}|rgba?\([\d\s.,%]+\)|transparent)$/', $value ),
			'border_style' === $key           => in_array( $value, array( 'solid', 'dashed', 'dotted', 'double', 'none' ), true ),
			'background_repeat' === $key      => in_array( $value, array( 'no-repeat', 'repeat', 'repeat-x', 'repeat-y' ), true ),
			'background_size' === $key        => in_array( $value, array( 'cover', 'contain', 'auto' ), true ),
			'background_position' === $key    => (bool) preg_match( '/^[a-z% 0-9]{1,30}$/', $value ),
			default                           => (bool) preg_match( '/^-?\d+(\.\d+)?(px|%|em|rem|vh|vw)?$/', $value ),
		};
		if ( ! $ok ) {
			throw new ToolError( 'Design option ' . esc_html( $key ) . ' has a value that is not allowed: "' . esc_html( $value ) . '". Sizes are numbers with px, %, em, rem, vh or vw; colours are #hex or rgb()/rgba().' );
		}
		return preg_match( '/^-?\d+(\.\d+)?$/', $value ) ? $value . 'px' : $value;
	}

	/**
	 * Stored attributes for tool input: attrs, plus design (css) and responsive (offset) helpers.
	 *
	 * @param string                    $tag     Tag.
	 * @param array<string, mixed>      $input   attrs, design, responsive, width.
	 * @param array<string, mixed>|null $current Current element node when updating.
	 * @return array<string, string|null> Name => stored value, null to remove.
	 * @throws ToolError When a name or value is not allowed.
	 */
	public static function atts_from_input( string $tag, array $input, ?array $current = null ): array {
		$atts = array();
		foreach ( (array) ( $input['attrs'] ?? array() ) as $name => $value ) {
			if ( ! is_string( $name ) || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/', $name ) ) {
				throw new ToolError( 'Attribute names use letters, digits, _ and -. "' . esc_html( (string) $name ) . '" is not allowed.' );
			}
			if ( 'content' === $name ) {
				throw new ToolError( 'The text of an element is given as content, next to attrs, not as an attribute.' );
			}
			$atts[ $name ] = null === $value ? null : self::stored( $tag, $name, $value );
		}
		if ( isset( $input['width'] ) ) {
			$atts['width'] = self::width( (string) $input['width'] );
		}
		if ( isset( $input['responsive'] ) && is_array( $input['responsive'] ) ) {
			$offset         = self::offset( $input['responsive'] );
			$atts['offset'] = '' === $offset ? null : $offset;
		}
		if ( isset( $input['design'] ) && is_array( $input['design'] ) ) {
			$css         = self::css( $atts['css'] ?? ( null !== $current ? Shortcodes::att( $current, 'css' ) : null ), $input['design'] );
			$atts['css'] = '' === $css ? null : $css;
		}
		return $atts;
	}

	/**
	 * A node for an element given as JSON: tag, attrs, design, content or children.
	 *
	 * @param mixed $input Element.
	 * @param int   $depth Depth.
	 * @param int   $count Elements built so far.
	 * @return array<string, mixed>
	 * @throws ToolError When the element is malformed.
	 */
	public static function element( $input, int $depth = 0, int &$count = 0 ): array {
		if ( ! is_array( $input ) || ! is_string( $input['tag'] ?? null ) || ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $input['tag'] ) ) {
			throw new ToolError( 'Every element needs a tag such as "vc_column_text" (see kuduy-wpbakery-elements).' );
		}
		if ( $depth > self::MAX_DEPTH || ++$count > self::MAX_ELEMENTS ) {
			throw new ToolError( 'This layout is too deep or too large (at most ' . (int) self::MAX_DEPTH . ' levels and ' . (int) self::MAX_ELEMENTS . ' elements per call).' );
		}
		$tag = $input['tag'];
		if ( isset( $input['content'] ) && ! empty( $input['children'] ) ) {
			throw new ToolError( 'Give an element either content (text) or children (elements), not both. Element: ' . esc_html( $tag ) . '.' );
		}
		$children = array();
		foreach ( (array) ( $input['children'] ?? array() ) as $child ) {
			$children[] = self::element( $child, $depth + 1, $count );
		}
		if ( isset( $input['content'] ) ) {
			$children = self::content_nodes( $tag, (string) $input['content'] );
		}
		$atts = array_filter( self::atts_from_input( $tag, $input ), static fn( $v ) => null !== $v );
		return Shortcodes::element( $tag, $atts, $children, self::container( $tag ) );
	}

	/**
	 * A row node with its columns.
	 *
	 * @param array<string, mixed> $input layout or columns, attrs, design.
	 * @param bool                 $inner Whether it sits inside a column.
	 * @return array<string, mixed>
	 * @throws ToolError When the columns are malformed.
	 */
	public static function row( array $input, bool $inner ): array {
		$row_tag = $inner ? 'vc_row_inner' : 'vc_row';
		$col_tag = self::ROWS[ $row_tag ];
		$columns = array();
		if ( ! empty( $input['columns'] ) && is_array( $input['columns'] ) ) {
			$columns = array_values( $input['columns'] );
		} else {
			foreach ( self::widths( (string) ( $input['layout'] ?? '1/1' ) ) as $width ) {
				$columns[] = array( 'width' => $width );
			}
		}
		if ( count( $columns ) > 12 ) {
			throw new ToolError( 'A row holds at most 12 columns.' );
		}
		$count    = 0;
		$children = array();
		foreach ( $columns as $column ) {
			if ( ! is_array( $column ) ) {
				throw new ToolError( 'Each column is an object: width, and optionally attrs, design, responsive, elements.' );
			}
			$children[] = self::column( $column, $col_tag, $count );
		}
		$atts = array_filter(
			self::atts_from_input(
				$row_tag,
				array_diff_key(
					$input,
					array(
						'width'      => 1,
						'responsive' => 1,
					)
				)
			),
			static fn( $v ) => null !== $v
		);
		return Shortcodes::element( $row_tag, $atts, $children, true );
	}

	/**
	 * A column node with its elements.
	 *
	 * @param array<string, mixed> $input   width, attrs, design, responsive, elements.
	 * @param string               $col_tag vc_column or vc_column_inner.
	 * @param int                  $count   Elements built so far.
	 * @return array<string, mixed>
	 * @throws ToolError When an element is malformed.
	 */
	public static function column( array $input, string $col_tag, int &$count = 0 ): array {
		$elements = array();
		foreach ( (array) ( $input['elements'] ?? array() ) as $element ) {
			$node = self::element( $element, 2, $count );
			if ( 'vc_column' === $col_tag && 'vc_row' === $node['tag'] ) {
				throw new ToolError( 'A row inside a column is a vc_row_inner with vc_column_inner columns (use kuduy-wpbakery-add-row with the column as parent).' );
			}
			$elements[] = $node;
		}
		$input['width'] = self::width( (string) ( $input['width'] ?? '1/1' ) );
		$atts           = array_filter( self::atts_from_input( $col_tag, $input ), static fn( $v ) => null !== $v );
		if ( '1/1' === ( $atts['width'] ?? '' ) ) {
			unset( $atts['width'] );
		}
		return Shortcodes::element( $col_tag, $atts, $elements, true );
	}

	/**
	 * Check a layout: structure errors that would break the page, and warnings worth knowing.
	 *
	 * @param list<array<string, mixed>> $nodes Layout.
	 * @return array{errors: list<array<string, string>>, warnings: list<array<string, string>>}
	 */
	public static function validate( array $nodes ): array {
		$report = array(
			'errors'   => array(),
			'warnings' => array(),
		);
		self::check( $nodes, '', null, $report );
		return $report;
	}

	/**
	 * Check one level.
	 *
	 * @param list<array<string, mixed>>                                                        $nodes  Nodes.
	 * @param string                                                                            $into Parent id.
	 * @param array<string, mixed>|null                                                         $above  Parent node.
	 * @param array{errors: list<array<string, string>>, warnings: list<array<string, string>>} $report Report.
	 */
	private static function check( array $nodes, string $into, ?array $above, array &$report ): void {
		$index      = 0;
		$parent_tag = null === $above ? '' : (string) $above['tag'];
		foreach ( $nodes as $node ) {
			if ( 'text' === $node['type'] ) {
				if ( ! empty( $node['orphan'] ) && preg_match_all( '#\[/([A-Za-z0-9_-]+)\]#', $node['raw'], $m ) ) {
					$report['errors'][] = array(
						'at'      => '' === $into ? 'top level' : $into,
						'problem' => 'Closing tag without a matching opening tag: ' . implode( ', ', array_unique( $m[0] ) ) . '. Tags are closed in the wrong order, or an opening tag is missing.',
					);
				}
				if ( self::counts( $node ) ) {
					$id = self::child_id( $into, $index++ );
					if ( '' === $parent_tag || isset( self::ROWS[ $parent_tag ] ) ) {
						$report['warnings'][] = array(
							'at'      => $id,
							'problem' => 'Text outside a column. WPBakery wraps loose content in a row with a text block when the page is next opened in its editor.',
						);
					}
				}
				continue;
			}
			$id       = self::child_id( $into, $index++ );
			$tag      = (string) $node['tag'];
			$role     = self::role( $node );
			$settings = WpBakery::settings( $tag );
			$error    = match ( true ) {
				'vc_column' === $tag && 'vc_row' !== $parent_tag                  => 'A column (vc_column) must sit directly inside a row (vc_row).',
				'vc_column_inner' === $tag && 'vc_row_inner' !== $parent_tag      => 'An inner column (vc_column_inner) must sit directly inside an inner row (vc_row_inner).',
				'vc_row' === $tag && ! in_array( $parent_tag, array( '', 'vc_section' ), true ) => 'A row (vc_row) cannot sit inside ' . $parent_tag . '. Inside a column use vc_row_inner with vc_column_inner.',
				'vc_row_inner' === $tag && 'vc_column' !== $parent_tag            => 'An inner row (vc_row_inner) must sit directly inside a column (vc_column).',
				'vc_section' === $tag && '' !== $parent_tag                       => 'A section (vc_section) must be at the top level.',
				isset( self::ROWS[ $parent_tag ] ) && self::ROWS[ $parent_tag ] !== $tag => 'A row (' . $parent_tag . ') may only hold ' . self::ROWS[ $parent_tag ] . ' columns, not ' . $tag . '.',
				( 'row' === $role || 'column' === $role || 'vc_section' === $tag ) && empty( $node['closed'] ) => $tag . ' has no closing tag [/' . $tag . '].',
				default => null,
			};
			if ( null !== $error ) {
				$report['errors'][] = array(
					'at'      => $id,
					'problem' => $error,
				);
			}
			if ( '' === $parent_tag && 'row' !== $role && 'vc_section' !== $tag ) {
				$report['warnings'][] = array(
					'at'      => $id,
					'problem' => $tag . ' is at the top level, outside a row and column. WPBakery wraps it in a row when the page is next opened in its editor.',
				);
			}
			if ( null === $settings && ! shortcode_exists( $tag ) ) {
				$report['warnings'][] = array(
					'at'      => $id,
					'problem' => 'Unknown element "' . $tag . '": no active plugin or theme registers it, so it shows as plain text on the page. It is kept as it is.',
				);
			} elseif ( null !== $settings ) {
				self::check_atts( $node, $id, $settings, $report );
				$only = $above ? self::only( WpBakery::settings( $parent_tag ) ) : array();
				if ( $only && ! in_array( $tag, $only, true ) ) {
					$report['warnings'][] = array(
						'at'      => $id,
						'problem' => $parent_tag . ' is registered to hold only ' . implode( ', ', $only ) . '; ' . $tag . ' may not show in WPBakery\'s editor.',
					);
				}
			}
			if ( 'column' === $role && ! preg_match( '#^\d{1,2}/\d{1,2}$#', Shortcodes::att( $node, 'width' ) ?? '1/1' ) ) {
				$report['warnings'][] = array(
					'at'      => $id,
					'problem' => 'Column width "' . (string) Shortcodes::att( $node, 'width' ) . '" is not a fraction such as 1/2.',
				);
			}
			self::check( $node['children'], $id, $node, $report );
		}//end foreach
	}

	/**
	 * Tags a registered parent accepts as children ("as_parent" => only), or an empty list.
	 *
	 * @param array<string, mixed>|null $settings Parent settings.
	 * @return list<string>
	 */
	private static function only( ?array $settings ): array {
		$only = $settings['as_parent']['only'] ?? '';
		return is_string( $only ) && '' !== $only ? array_values( array_filter( array_map( 'trim', explode( ',', $only ) ) ) ) : array();
	}

	/**
	 * Attribute checks against the registered parameters.
	 *
	 * @param array<string, mixed>                                                              $node     Element node.
	 * @param string                                                                            $id       Id.
	 * @param array<string, mixed>                                                              $settings Element settings.
	 * @param array{errors: list<array<string, string>>, warnings: list<array<string, string>>} $report   Report.
	 */
	private static function check_atts( array $node, string $id, array $settings, array &$report ): void {
		$params = array();
		foreach ( (array) ( $settings['params'] ?? array() ) as $param ) {
			if ( is_array( $param ) && is_string( $param['param_name'] ?? null ) ) {
				$params[ $param['param_name'] ] = $param;
			}
		}
		if ( ! $params ) {
			return;
		}
		foreach ( $node['atts'] as $att ) {
			$name = $att['name'];
			if ( null === $name ) {
				continue;
			}
			if ( ! isset( $params[ $name ] ) ) {
				$report['warnings'][] = array(
					'at'      => $id,
					'problem' => 'Attribute "' . $name . '" is not a registered setting of ' . $node['tag'] . ' (it is kept; a theme or add-on may still read it).',
				);
				continue;
			}
			$options = $params[ $name ]['value'] ?? null;
			if ( 'dropdown' === ( $params[ $name ]['type'] ?? '' ) && is_array( $options ) && $options ) {
				$allowed = array_map( 'strval', array_values( array_map( static fn( $o ) => is_array( $o ) ? ( $o['value'] ?? reset( $o ) ) : $o, $options ) ) );
				if ( ! in_array( $att['value'], $allowed, true ) ) {
					$report['warnings'][] = array(
						'at'      => $id,
						'problem' => 'Attribute "' . $name . '" of ' . $node['tag'] . ' is "' . $att['value'] . '", which is not one of its options: ' . implode( ', ', array_slice( $allowed, 0, 30 ) ) . '.',
					);
				}
			}
		}//end foreach
	}

	/**
	 * Count elements in a layout.
	 *
	 * @param list<array<string, mixed>> $nodes Nodes.
	 */
	public static function count( array $nodes ): int {
		$n = 0;
		foreach ( $nodes as $node ) {
			if ( 'element' === $node['type'] ) {
				$n += 1 + self::count( $node['children'] );
			}
		}
		return $n;
	}
}
