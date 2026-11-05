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
	 * Refuse settings a type does not know. Classic: control names from both control lists, per-device keys of
	 * responsive controls, and Elementor's "__globals__" / "__dynamic__" references. v4: prop names, each value
	 * checked with the prop type's own validate().
	 *
	 * @param object               $type     Widget or element type object.
	 * @param array<string, mixed> $settings Settings to set.
	 * @throws ToolError Naming the unknown or invalid keys.
	 */
	public static function check_settings( object $type, array $settings ): void {
		$bad = array();
		if ( self::is_atomic( $type ) ) {
			$props = (array) $type::get_props_schema();
			foreach ( $settings as $key => $value ) {
				$prop = $props[ $key ] ?? null;
				if ( null === $prop ) {
					$bad[] = $key . ' (unknown prop)';
				} elseif ( null !== $value && is_object( $prop ) && method_exists( $prop, 'validate' ) && ! $prop->validate( $value ) ) {
					$bad[] = $key . ' (value does not match its schema; see kuduy-elementor-widget-schema)';
				}
			}
		} else {
			$known   = self::control_names( $type );
			$devices = (array) \Elementor\Plugin::$instance->breakpoints->get_active_devices_list();
			foreach ( array_keys( $settings ) as $key ) {
				$key = (string) $key;
				if ( isset( $known[ $key ] ) || str_starts_with( $key, '__' ) ) {
					continue;
				}
				$base = '';
				foreach ( $devices as $device ) {
					if ( 'desktop' !== $device && str_ends_with( $key, '_' . $device ) ) {
						$base = substr( $key, 0, -strlen( '_' . $device ) );
					}
				}
				if ( '' === $base || empty( $known[ $base ] ) ) {
					$bad[] = $key;
				}
			}
		}//end if
		if ( $bad ) {
			throw new ToolError( 'Unknown or invalid settings for "' . esc_html( (string) $type->get_name() ) . '": ' . esc_html( implode( ', ', array_slice( $bad, 0, 10 ) ) ) . '. See kuduy-elementor-widget-schema.' );
		}
	}

	/**
	 * Control names of a classic type => whether the control is responsive (both control lists).
	 *
	 * @param object $type Type object.
	 * @return array<string, bool>
	 */
	public static function control_names( object $type ): array {
		$stack = (array) $type->get_stack();
		$names = array();
		foreach ( (array) ( $stack['controls'] ?? array() ) + (array) ( $stack['style_controls'] ?? array() ) as $name => $control ) {
			$names[ (string) $name ] = is_array( $control ) && ( ! empty( $control['is_responsive'] ) || isset( $control['responsive'] ) );
		}
		return $names;
	}

	/**
	 * Human-readable name of the plugin that provides a type (its "Plugin Name" header), or the source label.
	 *
	 * @param object $type Type object.
	 */
	public static function source_name( object $type ): string {
		$source = self::source( $type );
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $data ) {
			if ( str_starts_with( (string) $file, $source . '/' ) ) {
				return wp_strip_all_tags( (string) ( $data['Name'] ?? $source ) );
			}
		}
		return $source;
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
