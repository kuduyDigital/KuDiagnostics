<?php
/**
 * Tools: kuduy-avada-status, -elements, -tree, -structure, -content, -library, -global-options, -validate.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Avada;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;
use Kuduy\Mcp\Modules\WpBakery\Layout;
use Kuduy\Mcp\Modules\WpBakery\WpBakery;

/**
 * Read-only Avada tools: what is installed and switched on, the elements registered on this site with their
 * settings, a page as a compact tree or full structure, its raw shortcodes and custom CSS, the Library and Layouts,
 * Global Options (colours, typography, breakpoints; never secrets), and a validator.
 */
final class AvadaRead implements Tool {

	/**
	 * Mode.
	 *
	 * @param string $mode status | elements | tree | structure | content | library | global-options | validate.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-avada-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'status'         => __( 'Avada status', 'kuduy-mcp' ),
			'elements'       => __( 'Avada Builder elements', 'kuduy-mcp' ),
			'tree'           => __( 'Avada page tree', 'kuduy-mcp' ),
			'structure'      => __( 'Avada page structure', 'kuduy-mcp' ),
			'content'        => __( 'Avada page shortcodes and CSS', 'kuduy-mcp' ),
			'library'        => __( 'Avada Library and Layouts', 'kuduy-mcp' ),
			'global-options' => __( 'Avada Global Options', 'kuduy-mcp' ),
			default          => __( 'Validate an Avada layout', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'status'         => 'Reports what Avada this site has: theme and child theme, Avada Builder and Avada Core versions, which combination (theme_and_builder, theme_without_builder, builder_with_other_theme, not_installed), post types the builder is on for, how many elements are registered and enabled, whether Layouts, the Library, Avada Forms and WooCommerce are there, and other page builders on the site. Call first.',
			'elements'       => 'Lists the Avada Builder elements registered on this site (Avada\'s own and third-party): shortcode, name, whether it is a parent with child items, enabled. With tag it returns every setting of that element: name, type, heading, default, options (value => label), group, dependencies, responsive. Filter with search.',
			'tree'           => 'Returns an Avada page as a compact tree, one line per container, row, column, element and global element with its id, plus the tree as text and a hash to pass as if_hash when editing. Start here; ids are positions such as "0.0.1.2" and change after every edit.',
			'structure'      => 'Returns an Avada page in full: every container, row, column and element with id, parent id, tag, name, settings (attrs), responsive values (medium, small), content, column width, global element id, and settings that are not registered for the element. element_id returns one part.',
			'content'        => 'Returns the raw Avada shortcodes of a page exactly as stored, its builder (avada, elementor, wpbakery, none), whether Avada Builder is switched on for it, and its custom CSS.',
			'library'        => 'Lists the Avada Library (global elements, columns and containers saved for reuse, and templates) and Avada Layouts with their sections (header, footer, content, page title bar): id, title, status, category. Read one with kuduy-avada-tree.',
			'global-options' => 'Reads Avada Global Options that shape the design: colours, typography, breakpoints, grid and layout widths (secret settings such as API keys are never returned). Read only: Global Options apply to the whole site and are changed in Avada > Options.',
			default          => 'Checks an Avada layout without saving: shortcode syntax, closing tags, containers/rows/columns in the right places, nested columns, unknown elements, missing global elements, option values that are not among an element\'s options. Give post_id or content.',
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
			'status', 'global-options' => array(),
			'elements'       => array(
				'tag'    => array(
					'type'      => 'string',
					'maxLength' => 64,
				),
				'search' => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
			),
			'structure'      => array(
				'post_id'    => $post,
				'element_id' => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
			),
			'library'        => array(
				'kind'   => array(
					'type'    => 'string',
					'enum'    => array( 'all', 'elements', 'templates', 'layouts', 'sections' ),
					'default' => 'all',
				),
				'search' => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
			),
			'validate'       => array(
				'post_id' => $post,
				'content' => array(
					'type'      => 'string',
					'maxLength' => 1000000,
				),
			),
			default          => array( 'post_id' => $post ),
		};//end match
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => in_array( $this->mode, array( 'tree', 'structure', 'content' ), true ) ? array( 'post_id' ) : array(),
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
			'status'         => array(
				'combination' => array( 'type' => 'string' ),
				'theme'       => array( 'type' => 'object' ),
				'builder'     => array( 'type' => 'object' ),
				'core'        => array( 'type' => 'object' ),
			),
			'elements'       => array(
				'total'    => array( 'type' => 'integer' ),
				'elements' => $list,
				'element'  => array( 'type' => 'object' ),
			),
			'tree'           => array(
				'post'     => array( 'type' => 'object' ),
				'builder'  => array( 'type' => 'string' ),
				'hash'     => array( 'type' => 'string' ),
				'elements' => array( 'type' => 'integer' ),
				'items'    => $list,
				'tree'     => array( 'type' => 'string' ),
			),
			'structure'      => array(
				'post'      => array( 'type' => 'object' ),
				'builder'   => array( 'type' => 'string' ),
				'hash'      => array( 'type' => 'string' ),
				'structure' => $list,
			),
			'content'        => array(
				'post'           => array( 'type' => 'object' ),
				'builder'        => array( 'type' => 'string' ),
				'builder_active' => array( 'type' => 'boolean' ),
				'hash'           => array( 'type' => 'string' ),
				'content'        => array( 'type' => 'string' ),
				'custom_css'     => array( 'type' => 'string' ),
			),
			'library'        => array( 'items' => $list ),
			'global-options' => array( 'options' => array( 'type' => 'object' ) ),
			default          => array(
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
		if ( 'global-options' === $this->mode ) {
			return 'edit_theme_options';
		}
		$post = Content::post( (int) ( $input['post_id'] ?? 0 ) );
		return null === $post ? 'edit_posts' : array( 'edit_post', $post->ID );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		if ( 'status' === $this->mode ) {
			return true === Avada::available() || wp_get_theme( 'Avada' )->exists() ? true : 'Neither the Avada theme nor Avada Builder is installed on this site.';
		}
		if ( 'global-options' === $this->mode ) {
			return false !== get_option( 'fusion_options', false ) ? true : 'This site has no Avada Global Options.';
		}
		return Avada::available();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the page or element is missing.
	 */
	public function execute( array $input ): array {
		switch ( $this->mode ) {
			case 'status':
				return Avada::status();
			case 'elements':
				return self::elements( $input );
			case 'library':
				return self::library( $input );
			case 'global-options':
				return array( 'options' => (object) self::global_options() );
		}
		if ( 'validate' === $this->mode && isset( $input['content'] ) ) {
			return self::report( Avada::parse( (string) $input['content'] ) );
		}
		if ( empty( $input['post_id'] ) ) {
			throw new ToolError( 'Give post_id (a saved page) or content (shortcode text).' );
		}
		$post = Avada::post( (int) $input['post_id'] );
		AuditLog::target( $post->post_type, (string) $post->ID );
		$nodes = Avada::parse( $post->post_content );
		if ( 'validate' === $this->mode ) {
			return self::report( $nodes );
		}
		$head = array(
			'post'    => Content::summary( $post ) + WpBakery::links( $post ),
			'builder' => Avada::builder( $post ),
			'hash'    => WpBakery::hash( $post->post_content ),
		);
		if ( 'content' === $this->mode ) {
			return $head + array(
				'builder_active' => 'active' === get_post_meta( $post->ID, Avada::META_STATUS, true ),
				'content'        => $post->post_content,
				'custom_css'     => (string) get_post_meta( $post->ID, Avada::META_CSS, true ),
			);
		}
		if ( 'tree' === $this->mode ) {
			$outline = AvadaLayout::outline( $nodes );
			return $head + array(
				'elements' => AvadaLayout::count( $nodes ),
				'items'    => $outline,
				'tree'     => Layout::drawing( $outline ),
			);
		}
		$id = (string) ( $input['element_id'] ?? '' );
		if ( '' === $id ) {
			return $head + array( 'structure' => AvadaLayout::structure( $nodes ) );
		}
		$path   = Layout::path( $nodes, $id );
		$dot    = strrpos( $id, '.' );
		$parent = false === $dot ? '' : substr( $id, 0, $dot );
		$one    = AvadaLayout::structure( array( Layout::at( $nodes, $path ) ), $parent );
		return $head + array( 'structure' => self::renumber( $one, Layout::child_id( $parent, 0 ), $id ) );
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
				foreach ( $items[ $i ]['children'] as $c => $child ) {
					$items[ $i ]['children'][ $c ]['parent_id'] = $items[ $i ]['id'];
				}
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
		$report = AvadaLayout::validate( $nodes );
		return array(
			'valid'    => ! $report['errors'],
			'elements' => AvadaLayout::count( $nodes ),
			'errors'   => $report['errors'],
			'warnings' => array_slice( $report['warnings'], 0, 100 ),
		);
	}

	/**
	 * Registered elements, or one with its settings.
	 *
	 * @param array<string, mixed> $input tag, search.
	 * @return array<string, mixed>
	 * @throws ToolError When the element is not registered.
	 */
	private static function elements( array $input ): array {
		$map     = Avada::map();
		$enabled = Avada::enabled();
		$tag     = (string) ( $input['tag'] ?? '' );
		if ( '' !== $tag ) {
			if ( ! isset( $map[ $tag ] ) ) {
				throw new ToolError( 'No Avada Builder element "' . esc_html( $tag ) . '" is registered on this site. See kuduy-avada-elements without tag.' );
			}
			return array(
				'total'   => count( $map ),
				'element' => self::summary( $tag, $map[ $tag ], $enabled ) + array( 'params' => self::params( $tag ) ),
			);
		}
		$search = strtolower( (string) ( $input['search'] ?? '' ) );
		$out    = array();
		foreach ( $map as $name => $module ) {
			$item = self::summary( $name, $module, $enabled );
			if ( '' === $search || str_contains( strtolower( $name . ' ' . $item['name'] ), $search ) ) {
				$out[] = $item;
			}
		}
		return array(
			'total'    => count( $out ),
			'elements' => $out,
		);
	}

	/**
	 * Summary of one element.
	 *
	 * @param string               $tag     Shortcode.
	 * @param array<string, mixed> $module  Module.
	 * @param list<string>         $enabled Enabled shortcodes (empty = all).
	 * @return array<string, mixed>
	 */
	private static function summary( string $tag, array $module, array $enabled ): array {
		return array(
			'tag'         => $tag,
			'name'        => wp_strip_all_tags( (string) ( $module['name'] ?? $tag ) ),
			'child'       => (string) ( $module['element_child'] ?? '' ),
			'has_content' => isset( Avada::params( $tag )['element_content'] ),
			'enabled'     => ! $enabled || in_array( $tag, $enabled, true ) || in_array( $tag, array_merge( array( AvadaLayout::CONTAINER ), array_keys( AvadaLayout::ROWS ), AvadaLayout::COLUMNS ), true ),
		);
	}

	/**
	 * Settings of one element.
	 *
	 * @param string $tag Shortcode.
	 * @return list<array<string, mixed>>
	 */
	private static function params( string $tag ): array {
		$out = array();
		foreach ( Avada::params( $tag ) as $name => $param ) {
			$item = array(
				'name'    => $name,
				'type'    => (string) ( $param['type'] ?? '' ),
				'heading' => wp_strip_all_tags( (string) ( $param['heading'] ?? '' ) ),
			);
			if ( isset( $param['default'] ) && is_scalar( $param['default'] ) ) {
				$item['default'] = (string) $param['default'];
			}
			if ( is_array( $param['value'] ?? null ) && $param['value'] && in_array( $item['type'], array( 'select', 'radio_button_set', 'checkbox_button_set', 'multiple_select' ), true ) ) {
				$options = array();
				foreach ( $param['value'] as $value => $label ) {
					$options[] = array(
						'value' => (string) $value,
						'label' => is_scalar( $label ) ? wp_strip_all_tags( (string) $label ) : (string) $value,
					);
				}
				$item['options'] = array_slice( $options, 0, 200 );
			}
			if ( '' !== (string) ( $param['group'] ?? '' ) ) {
				$item['group'] = wp_strip_all_tags( (string) $param['group'] );
			}
			if ( ! empty( $param['responsive'] ) ) {
				$item['responsive'] = true;
			}
			if ( is_array( $param['dependency'] ?? null ) && $param['dependency'] ) {
				$item['shown_when'] = array_values(
					array_filter(
						array_map(
							static fn( $d ) => is_array( $d ) ? array_intersect_key( $d, array_flip( array( 'element', 'value', 'operator' ) ) ) : null,
							$param['dependency']
						)
					)
				);
			}
			if ( 'element_content' === $name ) {
				$item['is_content'] = true;
			}
			$out[] = $item;
		}//end foreach
		return $out;
	}

	/**
	 * Library items and Layouts.
	 *
	 * @param array<string, mixed> $input kind, search.
	 * @return array<string, mixed>
	 */
	private static function library( array $input ): array {
		$kind  = (string) ( $input['kind'] ?? 'all' );
		$types = array(
			'elements'  => array( 'fusion_element', 'element_category' ),
			'templates' => array( 'fusion_template', 'template_category' ),
			'layouts'   => array( 'fusion_tb_layout', '' ),
			'sections'  => array( 'fusion_tb_section', 'fusion_tb_category' ),
		);
		$items = array();
		foreach ( $types as $name => [ $type, $taxonomy ] ) {
			if ( ( 'all' !== $kind && $kind !== $name ) || ! post_type_exists( $type ) ) {
				continue;
			}
			foreach ( get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => array( 'publish', 'draft', 'private' ),
					'posts_per_page' => 100,
					'orderby'        => 'title',
					'order'          => 'ASC',
					's'              => (string) ( $input['search'] ?? '' ),
				)
			) as $post ) {
				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					continue;
				}
				$terms   = '' !== $taxonomy ? wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'slugs' ) ) : array();
				$items[] = array(
					'id'       => $post->ID,
					'kind'     => $name,
					'title'    => (string) $post->post_title,
					'status'   => (string) $post->post_status,
					'category' => is_array( $terms ) ? implode( ', ', $terms ) : '',
				);
			}
		}//end foreach
		return array( 'items' => $items );
	}

	/**
	 * Design-related Global Options, without secrets.
	 *
	 * @return array<string, mixed>
	 */
	private static function global_options(): array {
		$all = get_option( 'fusion_options', array() );
		$out = array();
		$all = is_array( $all ) ? $all : array();
		// Sorted first, so a site with more than 400 design settings always gets the same, predictable first 400.
		ksort( $all );
		foreach ( $all as $key => $value ) {
			$key = (string) $key;
			if ( preg_match( '/key|secret|token|password|passwd|api|captcha|private|license|purchase|mailchimp|hubspot|client_id/i', $key ) ) {
				continue;
			}
			if ( ! preg_match( '/color|colour|typography|font|breakpoint|grid|site_width|layout|spacing|border_radius|button|heading|body_|link_/i', $key ) ) {
				continue;
			}
			if ( is_scalar( $value ) || ( is_array( $value ) && count( $value, COUNT_RECURSIVE ) <= 40 ) ) {
				$out[ $key ] = $value;
			}
			if ( count( $out ) >= 400 ) {
				break;
			}
		}
		ksort( $out );
		return $out;
	}
}
