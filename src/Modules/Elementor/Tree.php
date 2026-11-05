<?php
/**
 * Pure operations on an Elementor element tree (no saving): find, locate, insert, remove, fresh ids, validation.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Elementor;

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Element arrays as stored in `_elementor_data`: id, elType, widgetType, settings, elements, isInner.
 */
final class Tree {

	/**
	 * Ids already used in the document (new ids must not collide).
	 *
	 * @var array<string, true>
	 */
	private array $ids = array();

	/**
	 * Tree.
	 *
	 * @param list<array<string, mixed>> $elements Elements.
	 */
	public function __construct( public array $elements ) {
		Elementor::walk(
			$elements,
			function ( array $element ): void {
				$this->ids[ (string) ( $element['id'] ?? '' ) ] = true;
			}
		);
	}

	/**
	 * Element by id.
	 *
	 * @param string $id Element id.
	 * @return array<string, mixed>
	 * @throws ToolError When not found.
	 */
	public function get( string $id ): array {
		$found = null;
		Elementor::walk(
			$this->elements,
			static function ( array $element ) use ( $id, &$found ): void {
				if ( null === $found && (string) ( $element['id'] ?? '' ) === $id ) {
					$found = $element;
				}
			}
		);
		if ( null === $found ) {
			throw new ToolError( 'No element with id "' . esc_html( $id ) . '" on this page.' );
		}
		return $found;
	}

	/**
	 * Parent id (null = document root) and index of an element.
	 *
	 * @param string $id Element id.
	 * @return array{0:?string, 1:int}
	 * @throws ToolError When not found.
	 */
	public function locate( string $id ): array {
		$where = self::find_in( $this->elements, $id, null );
		if ( null === $where ) {
			throw new ToolError( 'No element with id "' . esc_html( $id ) . '" on this page.' );
		}
		return $where;
	}

	/**
	 * Replace an element (same id) everywhere in the tree.
	 *
	 * @param array<string, mixed> $element New element data.
	 */
	public function replace( array $element ): void {
		$this->elements = self::map( $this->elements, (string) $element['id'], static fn() => $element );
	}

	/**
	 * Remove an element and return it.
	 *
	 * @param string $id Element id.
	 * @return array<string, mixed>
	 */
	public function remove( string $id ): array {
		$removed        = $this->get( $id );
		$this->elements = self::without( $this->elements, $id );
		return $removed;
	}

	/**
	 * Insert under a parent (null = document root) at an index (null = end), after checking the nesting rules.
	 *
	 * @param array<string, mixed> $element Element.
	 * @param string|null          $parent_id Parent id or null for the root.
	 * @param int|null             $index   Position.
	 * @throws ToolError When the nesting is not allowed.
	 */
	public function insert( array $element, ?string $parent_id, ?int $index ): void {
		$parent_type = null === $parent_id ? null : (string) ( $this->get( $parent_id )['elType'] ?? '' );
		self::check_nesting( $parent_type, (string) ( $element['elType'] ?? '' ) );
		if ( 'column' === $parent_type && 'section' === ( $element['elType'] ?? '' ) ) {
			$element['isInner'] = true;
		}
		if ( null === $parent_id ) {
			array_splice( $this->elements, self::clamp( $index, count( $this->elements ) ), 0, array( $element ) );
			return;
		}
		$this->elements = self::map(
			$this->elements,
			$parent_id,
			static function ( array $node ) use ( $element, $index ): array {
				$children = array_values( (array) ( $node['elements'] ?? array() ) );
				array_splice( $children, self::clamp( $index, count( $children ) ), 0, array( $element ) );
				$node['elements'] = $children;
				return $node;
			}
		);
	}

	/**
	 * A copy of an element with fresh unique ids for it and all children.
	 *
	 * @param array<string, mixed> $element Element.
	 * @return array<string, mixed>
	 */
	public function with_new_ids( array $element ): array {
		$element['id']       = $this->new_id();
		$element['elements'] = array_map( fn( $child ) => $this->with_new_ids( (array) $child ), array_values( (array) ( $element['elements'] ?? array() ) ) );
		return $element;
	}

	/**
	 * A fresh id that is unique in this document.
	 */
	public function new_id(): string {
		do {
			$id = substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
		} while ( isset( $this->ids[ $id ] ) );
		$this->ids[ $id ] = true;
		return $id;
	}

	/**
	 * Number of elements.
	 */
	public function count(): int {
		$count = 0;
		Elementor::walk(
			$this->elements,
			static function () use ( &$count ): void {
				++$count;
			}
		);
		return $count;
	}

	/**
	 * Nesting rules of Elementor's layouts: sections hold columns, columns sit in sections, widgets hold nothing,
	 * widgets and columns cannot sit at the top level.
	 *
	 * @param string|null $parent_type Parent element type (null = document root).
	 * @param string      $child_type  Child element type.
	 * @throws ToolError When not allowed.
	 */
	public static function check_nesting( ?string $parent_type, string $child_type ): void {
		$ok = match ( true ) {
			'widget' === $parent_type                                  => false,
			'section' === $parent_type                                 => 'column' === $child_type,
			'column' === $child_type                                   => 'section' === $parent_type,
			null === $parent_type                                      => 'widget' !== $child_type,
			default                                                    => true,
		};
		if ( ! $ok ) {
			throw new ToolError(
				sprintf(
					'A "%s" cannot be placed %s. Sections hold columns, columns hold widgets or inner sections, containers hold anything but columns, and widgets go inside a container or column.',
					esc_html( $child_type ),
					null === $parent_type ? 'at the top level' : 'inside a "' . esc_html( $parent_type ) . '"'
				)
			);
		}
	}

	/**
	 * Where an id sits.
	 *
	 * @param list<array<string, mixed>> $elements Elements.
	 * @param string                     $id       Id.
	 * @param string|null                $parent_id Parent id.
	 * @return array{0:?string, 1:int}|null
	 */
	private static function find_in( array $elements, string $id, ?string $parent_id ): ?array {
		foreach ( $elements as $i => $element ) {
			if ( (string) ( $element['id'] ?? '' ) === $id ) {
				return array( $parent_id, $i );
			}
			$deeper = self::find_in( (array) ( $element['elements'] ?? array() ), $id, (string) ( $element['id'] ?? '' ) );
			if ( null !== $deeper ) {
				return $deeper;
			}
		}
		return null;
	}

	/**
	 * Apply a change to the element with this id.
	 *
	 * @param list<array<string, mixed>> $elements Elements.
	 * @param string                     $id       Id.
	 * @param callable                   $change   fn( array $element ): array.
	 * @return list<array<string, mixed>>
	 */
	private static function map( array $elements, string $id, callable $change ): array {
		$out = array();
		foreach ( $elements as $element ) {
			if ( (string) ( $element['id'] ?? '' ) === $id ) {
				$element = $change( $element );
			} elseif ( ! empty( $element['elements'] ) ) {
				$element['elements'] = self::map( array_values( (array) $element['elements'] ), $id, $change );
			}
			$out[] = $element;
		}
		return $out;
	}

	/**
	 * Tree without the element with this id.
	 *
	 * @param list<array<string, mixed>> $elements Elements.
	 * @param string                     $id       Id.
	 * @return list<array<string, mixed>>
	 */
	private static function without( array $elements, string $id ): array {
		$out = array();
		foreach ( $elements as $element ) {
			if ( (string) ( $element['id'] ?? '' ) === $id ) {
				continue;
			}
			if ( ! empty( $element['elements'] ) ) {
				$element['elements'] = self::without( array_values( (array) $element['elements'] ), $id );
			}
			$out[] = $element;
		}
		return $out;
	}

	/**
	 * Index within 0..max (null = max).
	 *
	 * @param int|null $index Index.
	 * @param int      $max   Upper bound.
	 */
	private static function clamp( ?int $index, int $max ): int {
		return null === $index ? $max : max( 0, min( $index, $max ) );
	}
}
