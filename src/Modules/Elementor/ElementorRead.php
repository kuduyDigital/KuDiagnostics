<?php
/**
 * Tools: kuduy-elementor-documents, kuduy-elementor-get, kuduy-elementor-widgets, kuduy-elementor-widget-schema,
 * kuduy-elementor-kit (one class, five modes). Read-only.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Elementor;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Reads Elementor documents, the element tree, widget schemas (v3 controls expanded per breakpoint, v4 JSON Schema)
 * and the Kit's global styles.
 */
final class ElementorRead implements Tool {

	/**
	 * Control types that only structure the editor panel and hold no value.
	 */
	private const UI_ONLY = array( 'section', 'tab', 'tabs', 'heading', 'divider', 'raw_html', 'button', 'deprecated_notice', 'alert', 'notice' );

	/**
	 * Mode.
	 *
	 * @param string $mode documents | get | widgets | schema | kit.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return match ( $this->mode ) {
			'schema' => 'kuduy-elementor-widget-schema',
			default  => 'kuduy-elementor-' . $this->mode,
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'documents' => __( 'List Elementor pages', 'kuduy-mcp' ),
			'get'       => __( 'Read an Elementor page', 'kuduy-mcp' ),
			'widgets'   => __( 'List Elementor widgets', 'kuduy-mcp' ),
			'schema'    => __( 'Elementor widget settings', 'kuduy-mcp' ),
			default     => __( 'Elementor global styles', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'documents' => 'Lists pages, posts and templates built with Elementor: id, title, document type, status, Elementor version, link.',
			'get'       => 'Returns an Elementor page\'s element tree (id, elType, widgetType, settings, children) and page settings. Optionally only the subtree of one element, or without settings for a compact outline. Also lists element types on the page that are not registered right now (their plugin is inactive): such pages cannot be saved safely.',
			'widgets'   => 'Lists the element and widget types Elementor can build on this site: name, title, v4 (atomic) or classic, categories, and the plugin that provides it (Elementor, Elementor Pro, an add-on).',
			'schema'    => 'Returns the settings a widget or element accepts. Classic widgets: every control with type, default and options; responsive controls list their per-device keys (e.g. "align", "align_tablet", "align_mobile"). v4 (atomic) elements: JSON Schema per prop, where values use {"$$type": …, "value": …}.',
			default     => 'Returns the active Elementor Kit\'s global colors and fonts (with ids used as global references), breakpoints and content width.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$props = match ( $this->mode ) {
			'documents' => array(
				'search' => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
			) + Content::paging_input(),
			'get'       => array(
				'id'               => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'element_id'       => array(
					'type'        => 'string',
					'description' => 'Only this element and its children.',
				),
				'include_settings' => array(
					'type'    => 'boolean',
					'default' => true,
				),
			),
			'widgets'   => array(
				'search' => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
			),
			'schema'    => array(
				'type' => array(
					'type'        => 'string',
					'description' => 'Widget type (e.g. "heading", "e-heading") or element type (e.g. "container", "e-flexbox").',
				),
			),
			default     => array(),
		};//end match
		$required = match ( $this->mode ) {
			'get'    => array( 'id' ),
			'schema' => array( 'type' ),
			default  => array(),
		};
		return array(
			'type'                 => 'object',
			'properties'           => $props,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		$list   = static fn( array $item ): array => array(
			'type'  => 'array',
			'items' => $item,
		);
		$object = array( 'type' => 'object' );
		$props  = match ( $this->mode ) {
			'documents' => array(
				'documents' => $list(
					array(
						'type'       => 'object',
						'properties' => array(
							'id'                => array( 'type' => 'integer' ),
							'title'             => array( 'type' => 'string' ),
							'type'              => array( 'type' => 'string' ),
							'post_type'         => array( 'type' => 'string' ),
							'status'            => array( 'type' => 'string' ),
							'elementor_version' => array( 'type' => 'string' ),
							'link'              => array( 'type' => 'string' ),
						),
					)
				),
				'total'     => array( 'type' => 'integer' ),
			),
			'get'       => array(
				'id'                => array( 'type' => 'integer' ),
				'title'             => array( 'type' => 'string' ),
				'type'              => array( 'type' => 'string' ),
				'status'            => array( 'type' => 'string' ),
				'elementor_version' => array( 'type' => 'string' ),
				'element_count'     => array( 'type' => 'integer' ),
				'unregistered'      => $list( array( 'type' => 'string' ) ),
				'page_settings'     => $object,
				'elements'          => $list( $object ),
			),
			'widgets'   => array(
				'elements' => $list( $object ),
				'widgets'  => $list( $object ),
			),
			'schema'    => array(
				'type'     => array( 'type' => 'string' ),
				'atomic'   => array( 'type' => 'boolean' ),
				'source'   => array( 'type' => 'string' ),
				'devices'  => $list( array( 'type' => 'string' ) ),
				'controls' => $list( $object ),
				'props'    => $object,
			),
			default     => array(
				'kit_id'        => array( 'type' => 'integer' ),
				'colors'        => $list( $object ),
				'fonts'         => $list( $object ),
				'breakpoints'   => $object,
				'content_width' => array( 'type' => array( 'string', 'integer', 'object', 'null' ) ),
			),
		};//end match
		return array(
			'type'       => 'object',
			'properties' => $props,
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
		$id = (int) ( $input['id'] ?? 0 );
		return 'get' === $this->mode && $id > 0 && get_post( $id ) ? array( 'edit_post', $id ) : 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return Elementor::available();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the document or type is unknown.
	 */
	public function execute( array $input ): array {
		return match ( $this->mode ) {
			'documents' => self::documents( $input ),
			'get'       => self::get( $input ),
			'widgets'   => self::widgets( (string) ( $input['search'] ?? '' ) ),
			'schema'    => self::schema( (string) $input['type'] ),
			default     => self::kit(),
		};
	}

	/**
	 * Elementor-built posts the user can edit.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private static function documents( array $input ): array {
		$types = array_keys( Content::post_types() );
		if ( post_type_exists( 'elementor_library' ) ) {
			$types[] = 'elementor_library';
		}
		$args = array(
			'post_type'      => array_values( array_unique( $types ) ),
			'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'posts_per_page' => (int) ( $input['per_page'] ?? 20 ),
			'paged'          => (int) ( $input['page'] ?? 1 ),
			'meta_key'       => '_elementor_edit_mode', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Elementor's own marker for built pages.
			'meta_value'     => 'builder', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
			'orderby'        => 'modified',
		);
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = (string) $input['search'];
		}
		$query = new \WP_Query( $args );
		$out   = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$doc   = \Elementor\Plugin::$instance->documents->get( $post->ID );
			$out[] = array(
				'id'                => $post->ID,
				'title'             => $post->post_title,
				'type'              => $doc ? (string) $doc->get_name() : '',
				'post_type'         => $post->post_type,
				'status'            => $post->post_status,
				'elementor_version' => (string) get_post_meta( $post->ID, '_elementor_version', true ),
				'link'              => (string) get_permalink( $post ),
			);
		}
		return array(
			'documents' => $out,
			'total'     => (int) $query->found_posts,
		);
	}

	/**
	 * Element tree of one document.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the element is not found.
	 */
	private static function get( array $input ): array {
		$doc      = Elementor::document( (int) $input['id'] );
		$post     = $doc->get_post();
		$elements = Elementor::elements( $doc );
		AuditLog::target( 'post', (string) $post->ID );

		$count = 0;
		Elementor::walk(
			$elements,
			static function () use ( &$count ): void {
				++$count;
			}
		);
		$tree = $elements;
		if ( ! empty( $input['element_id'] ) ) {
			$tree = array();
			Elementor::walk(
				$elements,
				static function ( array $element ) use ( &$tree, $input ): void {
					if ( (string) ( $element['id'] ?? '' ) === (string) $input['element_id'] ) {
						$tree = array( $element );
					}
				}
			);
			if ( ! $tree ) {
				throw new ToolError( 'No element with this id on the page.' );
			}
		}
		if ( false === ( $input['include_settings'] ?? true ) ) {
			$tree = self::outline( $tree );
		}
		$settings = get_post_meta( $post->ID, '_elementor_page_settings', true );
		return array(
			'id'                => $post->ID,
			'title'             => $post->post_title,
			'type'              => (string) $doc->get_name(),
			'status'            => $post->post_status,
			'elementor_version' => (string) get_post_meta( $post->ID, '_elementor_version', true ),
			'element_count'     => $count,
			'unregistered'      => Elementor::unregistered( $elements ),
			'page_settings'     => is_array( $settings ) && $settings ? $settings : new \stdClass(),
			'elements'          => $tree,
		);
	}

	/**
	 * Tree without settings.
	 *
	 * @param list<array<string, mixed>> $elements Elements.
	 * @return list<array<string, mixed>>
	 */
	private static function outline( array $elements ): array {
		$out = array();
		foreach ( $elements as $element ) {
			$out[] = array_filter(
				array(
					'id'         => $element['id'] ?? '',
					'elType'     => $element['elType'] ?? '',
					'widgetType' => $element['widgetType'] ?? null,
					'elements'   => self::outline( (array) ( $element['elements'] ?? array() ) ),
				),
				static fn( $v ) => null !== $v
			);
		}
		return $out;
	}

	/**
	 * Registered element and widget types.
	 *
	 * @param string $search Filter on name or title.
	 * @return array<string, mixed>
	 */
	private static function widgets( string $search ): array {
		$plugin = \Elementor\Plugin::$instance;
		$match  = static fn( string $name, string $title ): bool => '' === $search || false !== stripos( $name . ' ' . $title, $search );
		$out    = array(
			'elements' => array(),
			'widgets'  => array(),
		);
		foreach ( (array) $plugin->elements_manager->get_element_types() as $name => $type ) {
			if ( is_object( $type ) && $match( (string) $name, (string) $type->get_title() ) ) {
				$out['elements'][] = array(
					'name'   => (string) $name,
					'title'  => (string) $type->get_title(),
					'atomic' => Elementor::is_atomic( $type ),
					'source' => Elementor::source( $type ),
				);
			}
		}
		foreach ( (array) $plugin->widgets_manager->get_widget_types() as $name => $type ) {
			if ( is_object( $type ) && $match( (string) $name, (string) $type->get_title() ) ) {
				$out['widgets'][] = array(
					'name'       => (string) $name,
					'title'      => (string) $type->get_title(),
					'atomic'     => Elementor::is_atomic( $type ),
					'categories' => array_values( (array) $type->get_categories() ),
					'source'     => Elementor::source( $type ),
				);
			}
		}
		return $out;
	}

	/**
	 * Settings schema of one widget or element type.
	 *
	 * @param string $name Type name.
	 * @return array<string, mixed>
	 * @throws ToolError When the type is not registered.
	 */
	private static function schema( string $name ): array {
		$type = Elementor::type_object( $name );
		if ( null === $type ) {
			throw new ToolError( 'Unknown widget or element type. See kuduy-elementor-widgets.' );
		}
		$devices = array_values( (array) \Elementor\Plugin::$instance->breakpoints->get_active_devices_list( array( 'reverse' => true ) ) );
		$out     = array(
			'type'     => $name,
			'atomic'   => Elementor::is_atomic( $type ),
			'source'   => Elementor::source( $type ),
			'devices'  => $devices,
			'controls' => array(),
			'props'    => new \stdClass(),
		);
		if ( $out['atomic'] ) {
			$props = array();
			foreach ( (array) $type::get_props_schema() as $prop => $prop_type ) {
				$props[ (string) $prop ] = is_object( $prop_type ) && method_exists( $prop_type, 'to_json_schema' ) ? $prop_type->to_json_schema() : array();
			}
			$out['props'] = $props ? $props : new \stdClass();
			return $out;
		}
		// Controls with CSS selectors live in a separate "style_controls" list that get_controls() only merges inside
		// the editor (Performance::is_use_style_controls()); read both so style settings such as alignment are listed.
		$stack = (array) $type->get_stack();
		foreach ( (array) ( $stack['controls'] ?? array() ) + (array) ( $stack['style_controls'] ?? array() ) as $control ) {
			if ( ! is_array( $control ) || in_array( $control['type'] ?? '', self::UI_ONLY, true ) ) {
				continue;
			}
			$name_key = (string) ( $control['name'] ?? '' );
			// Per-device copies registered server-side carry a parent; they are listed under their base control.
			if ( '' === $name_key || ! empty( $control['responsive']['max'] ) || ! empty( $control['responsive']['min'] ) ) {
				continue;
			}
			$row = array(
				'name'    => $name_key,
				'type'    => (string) ( $control['type'] ?? '' ),
				'label'   => wp_strip_all_tags( (string) ( $control['label'] ?? '' ) ),
				'default' => $control['default'] ?? null,
				'section' => (string) ( $control['section'] ?? '' ),
				'tab'     => (string) ( $control['tab'] ?? '' ),
			);
			if ( ! empty( $control['options'] ) && is_array( $control['options'] ) ) {
				$row['options'] = array_map( static fn( $o ) => is_array( $o ) ? ( $o['title'] ?? '' ) : $o, $control['options'] );
			}
			if ( ! empty( $control['is_responsive'] ) || isset( $control['responsive'] ) ) {
				$row['device_keys'] = array_map( static fn( string $d ): string => 'desktop' === $d ? $name_key : $name_key . '_' . $d, $devices );
			}
			if ( ! empty( $control['selectors'] ) ) {
				$row['styles'] = true;
			}
			$out['controls'][] = $row;
		}//end foreach
		return $out;
	}

	/**
	 * Active Kit's global styles.
	 *
	 * @return array<string, mixed>
	 * @throws ToolError When there is no active Kit.
	 */
	private static function kit(): array {
		// Not the cached Kit document: after a save in the same request it would still hold the old settings.
		$kit_id = (int) \Elementor\Plugin::$instance->kits_manager->get_active_id();
		$kit    = $kit_id > 0 ? \Elementor\Plugin::$instance->documents->get( $kit_id, false ) : null;
		if ( ! $kit || ! $kit->get_id() ) {
			throw new ToolError( 'This site has no active Elementor Kit.' );
		}
		$settings = (array) $kit->get_settings();
		$colors   = array();
		foreach ( array( 'system_colors', 'custom_colors' ) as $group ) {
			foreach ( (array) ( $settings[ $group ] ?? array() ) as $item ) {
				$colors[] = array(
					'id'     => (string) ( $item['_id'] ?? '' ),
					'title'  => (string) ( $item['title'] ?? '' ),
					'color'  => (string) ( $item['color'] ?? '' ),
					'system' => 'system_colors' === $group,
				);
			}
		}
		$fonts = array();
		foreach ( array( 'system_typography', 'custom_typography' ) as $group ) {
			foreach ( (array) ( $settings[ $group ] ?? array() ) as $item ) {
				$fonts[] = array(
					'id'     => (string) ( $item['_id'] ?? '' ),
					'title'  => (string) ( $item['title'] ?? '' ),
					'family' => (string) ( $item['typography_font_family'] ?? '' ),
					'weight' => (string) ( $item['typography_font_weight'] ?? '' ),
					'system' => 'system_typography' === $group,
				);
			}
		}
		$breakpoints = array();
		foreach ( (array) \Elementor\Plugin::$instance->breakpoints->get_active_breakpoints() as $name => $breakpoint ) {
			$breakpoints[ (string) $name ] = is_object( $breakpoint ) ? (int) $breakpoint->get_value() : 0;
		}
		return array(
			'kit_id'        => (int) $kit->get_id(),
			'colors'        => $colors,
			'fonts'         => $fonts,
			'breakpoints'   => $breakpoints ? $breakpoints : new \stdClass(),
			'content_width' => $settings['container_width'] ?? null,
		);
	}
}
