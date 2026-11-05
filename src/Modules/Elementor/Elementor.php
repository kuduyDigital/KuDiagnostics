<?php
/**
 * Shared Elementor access: availability, documents, element trees, registered types.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Elementor;

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Only Elementor's public, long-standing API: Plugin::$instance managers and Document objects (RESEARCH.md §8).
 */
final class Elementor {

	/**
	 * True when Elementor is loaded, else the reason for the tool error.
	 */
	public static function available(): bool|string {
		return did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->documents )
			? true
			: 'Elementor is not active on this site.';
	}

	/**
	 * Elementor version, or ''.
	 */
	public static function version(): string {
		return defined( 'ELEMENTOR_VERSION' ) ? (string) constant( 'ELEMENTOR_VERSION' ) : '';
	}

	/**
	 * The Elementor document of a post the current user may edit.
	 *
	 * @param int  $id               Post id.
	 * @param bool $must_be_built    Require "built with Elementor".
	 * @return \Elementor\Core\Base\Document
	 * @throws ToolError When the post is missing or not an Elementor document.
	 */
	public static function document( int $id, bool $must_be_built = true ): object {
		$post = $id > 0 ? get_post( $id ) : null;
		$doc  = $post ? \Elementor\Plugin::$instance->documents->get( $post->ID, false ) : null;
		if ( ! $doc ) {
			throw new ToolError( 'No Elementor document with this id. See kuduy-elementor-documents.' );
		}
		if ( $must_be_built && ! $doc->is_built_with_elementor() ) {
			throw new ToolError( 'This post is not built with Elementor.' );
		}
		return $doc;
	}

	/**
	 * Stored element tree of a document.
	 *
	 * @param object $doc Document.
	 * @return list<array<string, mixed>>
	 */
	public static function elements( object $doc ): array {
		$data = $doc->get_elements_data();
		return is_array( $data ) ? array_values( $data ) : array();
	}

	/**
	 * Visit every element depth-first.
	 *
	 * @param list<array<string, mixed>> $elements Elements.
	 * @param callable                   $visit    fn( array $element, ?string $parent_id, int $depth ).
	 * @param string|null                $parent_id Parent id.
	 * @param int                        $depth    Depth.
	 */
	public static function walk( array $elements, callable $visit, ?string $parent_id = null, int $depth = 0 ): void {
		foreach ( $elements as $element ) {
			$visit( $element, $parent_id, $depth );
			self::walk( (array) ( $element['elements'] ?? array() ), $visit, (string) ( $element['id'] ?? '' ), $depth + 1 );
		}
	}

	/**
	 * Type name of an element: the widget type for widgets, else the element type.
	 *
	 * @param array<string, mixed> $element Element.
	 */
	public static function type( array $element ): string {
		return 'widget' === ( $element['elType'] ?? '' ) ? (string) ( $element['widgetType'] ?? '' ) : (string) ( $element['elType'] ?? '' );
	}

	/**
	 * Whether Elementor can build this element type right now (unregistered types are dropped on save).
	 *
	 * @param array<string, mixed> $element Element.
	 */
	public static function registered( array $element ): bool {
		$plugin = \Elementor\Plugin::$instance;
		return 'widget' === ( $element['elType'] ?? '' )
			? null !== $plugin->widgets_manager->get_widget_types( (string) ( $element['widgetType'] ?? '' ) )
			: null !== $plugin->elements_manager->get_element_types( (string) ( $element['elType'] ?? '' ) );
	}

	/**
	 * Element types in a tree that are not registered (their plugin is inactive or the widget was removed).
	 *
	 * @param list<array<string, mixed>> $elements Elements.
	 * @return list<string>
	 */
	public static function unregistered( array $elements ): array {
		$missing = array();
		self::walk(
			$elements,
			static function ( array $element ) use ( &$missing ): void {
				if ( ! self::registered( $element ) ) {
					$missing[ self::type( $element ) ] = true;
				}
			}
		);
		return array_keys( $missing );
	}

	/**
	 * The registered type object (widget or element) for a name, or null.
	 *
	 * @param string $name Widget type or element type.
	 */
	public static function type_object( string $name ): ?object {
		$plugin = \Elementor\Plugin::$instance;
		$widget = $plugin->widgets_manager->get_widget_types( $name );
		if ( is_object( $widget ) ) {
			return $widget;
		}
		$element = $plugin->elements_manager->get_element_types( $name );
		return is_object( $element ) ? $element : null;
	}

	/**
	 * Whether a type object is a v4 atomic element or widget (it has a props schema).
	 *
	 * @param object $type Type object.
	 */
	public static function is_atomic( object $type ): bool {
		return method_exists( $type, 'get_props_schema' );
	}

	/**
	 * Which plugin (or theme) defines a class: "elementor", "elementor-pro", an add-on folder, or "theme".
	 *
	 * @param object $type Type object.
	 */
	public static function source( object $type ): string {
		$file = ( new \ReflectionClass( $type ) )->getFileName();
		if ( false === $file ) {
			return 'unknown';
		}
		$file    = wp_normalize_path( $file );
		$plugins = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
		if ( str_starts_with( $file, $plugins ) ) {
			return (string) strtok( substr( $file, strlen( $plugins ) ), '/' );
		}
		return str_starts_with( $file, wp_normalize_path( get_theme_root() ) ) ? 'theme' : 'unknown';
	}
}
