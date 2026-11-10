<?php
/**
 * Shortcode parser and serializer for WPBakery Page Builder layouts.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\WpBakery;

/**
 * WPBakery stores a layout as nested WordPress shortcodes in post_content. This class turns that text into a tree and
 * back without loss: a tokenizer finds every tag, then a range parser pairs each opening tag with the first following
 * closing tag of the same name, exactly as WordPress does (which is why WPBakery needs vc_row_inner for nesting).
 * Every node keeps its original text, so an unchanged page serializes byte for byte and an edit only rewrites the
 * tag that was changed; unknown and third-party shortcodes are kept as they are.
 *
 * Node shapes. Text: [ 'type' => 'text', 'raw' => string ]. Element: [ 'type' => 'element', 'tag' => string,
 * 'atts' => list of [ 'name' => string|null, 'value' => string (as stored), 'raw' => string|null ],
 * 'open' => original opening tag or null once changed, 'self' => bool (written as [tag /]), 'closed' => bool,
 * 'children' => list of nodes ].
 */
final class Shortcodes {

	/**
	 * Longest content parsed, in bytes.
	 */
	public const MAX_BYTES = 1000000;

	/**
	 * Parse content into nodes.
	 *
	 * @param string   $content Shortcode content.
	 * @param callable $is_tag  fn( string $name ): bool — whether a name without a closing tag is a shortcode.
	 * @return list<array<string, mixed>>
	 */
	public static function parse( string $content, callable $is_tag ): array {
		$tokens = self::tokenize( $content );
		$closes = array();
		foreach ( $tokens as $i => $token ) {
			if ( 'close' === $token['kind'] ) {
				$closes[ $token['tag'] ][] = $i;
			}
		}
		// A name counts as a shortcode when it has a closing tag somewhere, or the site knows it.
		$known = array();
		foreach ( $tokens as $token ) {
			if ( 'text' !== $token['kind'] && ! isset( $known[ $token['tag'] ] ) ) {
				$known[ $token['tag'] ] = isset( $closes[ $token['tag'] ] ) || (bool) $is_tag( $token['tag'] );
			}
		}
		return self::merge_text( self::build( $tokens, 0, count( $tokens ), $closes, $known ) );
	}

	/**
	 * Split content into text, opening and closing tag tokens.
	 *
	 * @param string $content Content.
	 * @return list<array<string, mixed>>
	 */
	private static function tokenize( string $content ): array {
		$tokens = array();
		$length = strlen( $content );
		$text   = 0;
		$pos    = 0;
		while ( $pos < $length ) {
			$start = strpos( $content, '[', $pos );
			if ( false === $start ) {
				break;
			}
			$token = self::tag_at( $content, $start, $length );
			if ( null === $token ) {
				$pos = $start + 1;
				continue;
			}
			$end = $start + strlen( $token['raw'] );
			// [[tag]] is WordPress's escape for a literal [tag]: keep it as text.
			if ( $start > 0 && '[' === $content[ $start - 1 ] && $end < $length && ']' === $content[ $end ] ) {
				$pos = $end + 1;
				continue;
			}
			if ( $start > $text ) {
				$tokens[] = array(
					'kind' => 'text',
					'raw'  => substr( $content, $text, $start - $text ),
				);
			}
			$tokens[] = $token;
			$text     = $end;
			$pos      = $end;
		}//end while
		if ( $text < $length ) {
			$tokens[] = array(
				'kind' => 'text',
				'raw'  => substr( $content, $text ),
			);
		}
		return $tokens;
	}

	/**
	 * The tag starting at an opening bracket, or null when it is not one. Like WordPress, a tag ends at the first
	 * closing bracket: attribute values cannot contain one (WPBakery stores them encoded).
	 *
	 * @param string $content Content.
	 * @param int    $start   Offset of "[".
	 * @param int    $length  Content length.
	 * @return array<string, mixed>|null
	 */
	private static function tag_at( string $content, int $start, int $length ): ?array {
		$pos   = $start + 1;
		$close = $pos < $length && '/' === $content[ $pos ];
		if ( $close ) {
			++$pos;
		}
		$span = strspn( $content, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-', $pos );
		if ( 0 === $span ) {
			return null;
		}
		$tag  = substr( $content, $pos, $span );
		$pos += $span;
		if ( $pos >= $length ) {
			return null;
		}
		$next = $content[ $pos ];
		if ( $close ) {
			return ']' === $next ? array(
				'kind' => 'close',
				'tag'  => $tag,
				'raw'  => substr( $content, $start, $pos + 1 - $start ),
			) : null;
		}
		if ( ']' !== $next && '/' !== $next && ! ctype_space( $next ) ) {
			return null;
		}
		$end = strpos( $content, ']', $pos );
		if ( false === $end ) {
			return null;
		}
		$inner = substr( $content, $pos, $end - $pos );
		// A second "[" before the "]" means this bracket was plain text, not a tag.
		if ( str_contains( $inner, '[' ) ) {
			return null;
		}
		$self = str_ends_with( $inner, '/' );
		return array(
			'kind' => 'open',
			'tag'  => $tag,
			'raw'  => substr( $content, $start, $end + 1 - $start ),
			'atts' => self::parse_atts( $self ? substr( $inner, 0, -1 ) : $inner ),
			'self' => $self,
		);
	}

	/**
	 * Attributes of an opening tag, in order, each with its original text. Mirrors shortcode_parse_atts(): name="v",
	 * name='v', name=v, and nameless values.
	 *
	 * @param string $text Text between the tag name and the closing bracket.
	 * @return list<array{name: string|null, value: string, raw: string}>
	 */
	public static function parse_atts( string $text ): array {
		$atts = array();
		if ( ! preg_match_all( '/([\w-]+)\s*=\s*"([^"]*)"|([\w-]+)\s*=\s*\'([^\']*)\'|([\w-]+)\s*=\s*([^\s\'"]+)|"([^"]*)"|\'([^\']*)\'|(\S+)/', $text, $matches, PREG_SET_ORDER ) ) {
			return $atts;
		}
		foreach ( $matches as $m ) {
			if ( '' !== $m[1] ) {
				$atts[] = array(
					'name'  => $m[1],
					'value' => $m[2],
					'raw'   => $m[0],
				);
			} elseif ( '' !== ( $m[3] ?? '' ) ) {
				$atts[] = array(
					'name'  => $m[3],
					'value' => $m[4],
					'raw'   => $m[0],
				);
			} elseif ( '' !== ( $m[5] ?? '' ) ) {
				$atts[] = array(
					'name'  => $m[5],
					'value' => $m[6],
					'raw'   => $m[0],
				);
			} else {
				$atts[] = array(
					'name'  => null,
					'value' => (string) ( $m[9] ?? ( '' !== ( $m[8] ?? '' ) ? $m[8] : ( $m[7] ?? '' ) ) ),
					'raw'   => $m[0],
				);
			}//end if
		}//end foreach
		return $atts;
	}

	/**
	 * Build nodes from the tokens in [ $from, $to ).
	 *
	 * @param list<array<string, mixed>> $tokens Tokens.
	 * @param int                        $from   First token.
	 * @param int                        $to     One past the last token.
	 * @param array<string, list<int>>   $closes Closing tag positions per tag, ascending.
	 * @param array<string, bool>        $known  Whether each name is a shortcode.
	 * @return list<array<string, mixed>>
	 */
	private static function build( array $tokens, int $from, int $to, array $closes, array $known ): array {
		$nodes = array();
		$i     = $from;
		while ( $i < $to ) {
			$token = $tokens[ $i ];
			if ( 'open' !== $token['kind'] || empty( $known[ $token['tag'] ] ) ) {
				// Text, a closing tag without an opening one, or a bracket word that is not a shortcode.
				$nodes[] = array(
					'type'   => 'text',
					'raw'    => $token['raw'],
					'orphan' => 'close' === $token['kind'] && ! empty( $known[ $token['tag'] ] ),
				);
				++$i;
				continue;
			}
			$node = array(
				'type'     => 'element',
				'tag'      => $token['tag'],
				'atts'     => $token['atts'],
				'open'     => $token['raw'],
				'self'     => $token['self'],
				'closed'   => false,
				'children' => array(),
			);
			$end  = $token['self'] ? null : self::next_close( $closes[ $token['tag'] ] ?? array(), $i, $to );
			if ( null !== $end ) {
				$node['closed']   = true;
				$node['children'] = self::build( $tokens, $i + 1, $end, $closes, $known );
				$i                = $end + 1;
			} else {
				++$i;
			}
			$nodes[] = $node;
		}//end while
		return $nodes;
	}

	/**
	 * First closing tag position after $after and before $before, or null.
	 *
	 * @param list<int> $positions Ascending positions.
	 * @param int       $after     Lower bound (exclusive).
	 * @param int       $before    Upper bound (exclusive).
	 */
	private static function next_close( array $positions, int $after, int $before ): ?int {
		$low  = 0;
		$high = count( $positions );
		while ( $low < $high ) {
			$mid = intdiv( $low + $high, 2 );
			if ( $positions[ $mid ] <= $after ) {
				$low = $mid + 1;
			} else {
				$high = $mid;
			}
		}
		return isset( $positions[ $low ] ) && $positions[ $low ] < $before ? $positions[ $low ] : null;
	}

	/**
	 * Join neighbouring text nodes (recursively).
	 *
	 * @param list<array<string, mixed>> $nodes Nodes.
	 * @return list<array<string, mixed>>
	 */
	public static function merge_text( array $nodes ): array {
		$out = array();
		foreach ( $nodes as $node ) {
			$last = count( $out ) - 1;
			if ( 'text' === $node['type'] && $last >= 0 && 'text' === $out[ $last ]['type'] ) {
				$out[ $last ]['raw']   .= $node['raw'];
				$out[ $last ]['orphan'] = ! empty( $out[ $last ]['orphan'] ) || ! empty( $node['orphan'] );
				continue;
			}
			if ( 'element' === $node['type'] ) {
				$node['children'] = self::merge_text( $node['children'] );
			}
			$out[] = $node;
		}
		return $out;
	}

	/**
	 * Nodes back to shortcode text.
	 *
	 * @param list<array<string, mixed>> $nodes Nodes.
	 */
	public static function serialize( array $nodes ): string {
		$out = '';
		foreach ( $nodes as $node ) {
			if ( 'text' === $node['type'] ) {
				$out .= $node['raw'];
				continue;
			}
			$out .= $node['open'] ?? self::open_tag( $node );
			if ( ! empty( $node['closed'] ) ) {
				$out .= self::serialize( $node['children'] ) . '[/' . $node['tag'] . ']';
			}
		}
		return $out;
	}

	/**
	 * Opening tag of a changed or new element: untouched attributes keep their original text.
	 *
	 * @param array<string, mixed> $node Element node.
	 */
	private static function open_tag( array $node ): string {
		$out = '[' . $node['tag'];
		foreach ( $node['atts'] as $att ) {
			$out .= ' ' . ( $att['raw'] ?? ( null === $att['name'] ? '"' . $att['value'] . '"' : $att['name'] . '="' . $att['value'] . '"' ) );
		}
		return $out . ( ! empty( $node['self'] ) && empty( $node['closed'] ) ? ' /]' : ']' );
	}

	/**
	 * A new element node.
	 *
	 * @param string                     $tag      Tag.
	 * @param array<string, string>      $atts     Attribute values as stored (already encoded).
	 * @param list<array<string, mixed>> $children Children.
	 * @param bool                       $closed   Whether it gets a closing tag.
	 * @return array<string, mixed>
	 */
	public static function element( string $tag, array $atts, array $children, bool $closed ): array {
		$list = array();
		foreach ( $atts as $name => $value ) {
			$list[] = array(
				'name'  => (string) $name,
				'value' => $value,
				'raw'   => null,
			);
		}
		return array(
			'type'     => 'element',
			'tag'      => $tag,
			'atts'     => $list,
			'open'     => null,
			'self'     => false,
			'closed'   => $closed || (bool) $children,
			'children' => $children,
		);
	}

	/**
	 * A text node.
	 *
	 * @param string $raw Text.
	 * @return array<string, mixed>
	 */
	public static function text( string $raw ): array {
		return array(
			'type' => 'text',
			'raw'  => $raw,
		);
	}

	/**
	 * Set, change or remove attributes of an element node; only touched attributes are rewritten.
	 *
	 * @param array<string, mixed>       $node  Element node.
	 * @param array<string, string|null> $atts  Name => stored value, or null to remove.
	 * @return array<string, mixed>
	 */
	public static function with_atts( array $node, array $atts ): array {
		foreach ( $atts as $name => $value ) {
			$found = false;
			foreach ( $node['atts'] as $i => $att ) {
				if ( $att['name'] !== (string) $name ) {
					continue;
				}
				$found = true;
				if ( null === $value ) {
					unset( $node['atts'][ $i ] );
				} elseif ( $att['value'] !== $value ) {
					$node['atts'][ $i ]['value'] = $value;
					$node['atts'][ $i ]['raw']   = null;
				}
			}
			if ( ! $found && null !== $value ) {
				$node['atts'][] = array(
					'name'  => (string) $name,
					'value' => $value,
					'raw'   => null,
				);
			}
		}//end foreach
		$node['atts'] = array_values( $node['atts'] );
		$node['open'] = null;
		return $node;
	}

	/**
	 * Stored value of an attribute, or null.
	 *
	 * @param array<string, mixed> $node Element node.
	 * @param string               $name Attribute.
	 */
	public static function att( array $node, string $name ): ?string {
		foreach ( $node['atts'] as $att ) {
			if ( $att['name'] === $name ) {
				return $att['value'];
			}
		}
		return null;
	}

	/**
	 * A value as WPBakery stores it in an attribute: quotes and square brackets cannot appear inside a shortcode
	 * attribute, so WPBakery writes them as backtick sequences.
	 *
	 * @param string $value Plain value.
	 */
	public static function encode( string $value ): string {
		return str_replace( array( '[', ']', '"' ), array( '`{`', '`}`', '``' ), $value );
	}

	/**
	 * A stored attribute value as plain text (the reverse of encode()).
	 *
	 * @param string $value Stored value.
	 */
	public static function decode( string $value ): string {
		return str_replace( array( '`{`', '`}`', '``' ), array( '[', ']', '"' ), $value );
	}
}
