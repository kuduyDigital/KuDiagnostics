<?php
/**
 * Shared Avada access: detection, element registry, posts, saving.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Avada;

use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;
use Kuduy\Mcp\Modules\WpBakery\Shortcodes;
use Kuduy\Mcp\Modules\WpBakery\WpBakery;

/**
 * Avada Builder (plugin fusion-builder) keeps a layout as nested shortcodes in post_content, marks a page as built
 * with it in the meta fusion_builder_status = active, and keeps per-page CSS in _fusion_builder_custom_css. Elements
 * are registered with fusion_builder_map() into the global $all_fusion_builder_elements; outside its own screens the
 * maps are built on demand with awb_maybe_init_element_maps(). On save_post Avada resets its own CSS and JS caches,
 * so saving through wp_update_post() is the native path. Read from Avada 7.16.4 / Builder 3.16.4 (docs/plans/avada.md,
 * D31). The shortcode parser and tree operations are shared with the WPBakery module.
 */
final class Avada {

	/**
	 * Post meta: builder on for this post.
	 */
	public const META_STATUS = 'fusion_builder_status';

	/**
	 * Post meta: page custom CSS.
	 */
	public const META_CSS = '_fusion_builder_custom_css';

	/**
	 * Library and Layout post types (shared by many pages).
	 */
	public const SHARED_TYPES = array( 'fusion_element', 'fusion_template', 'fusion_tb_section' );

	/**
	 * Registered elements, per request.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $map = null;

	/**
	 * True when Avada Builder is loaded, else the reason.
	 */
	public static function available(): bool|string {
		return defined( 'FUSION_BUILDER_VERSION' ) && function_exists( 'fusion_builder_map' ) ? true : 'Avada Builder (the fusion-builder plugin) is not active on this site.';
	}

	/**
	 * Avada Builder version, or ''.
	 */
	public static function version(): string {
		return defined( 'FUSION_BUILDER_VERSION' ) ? (string) constant( 'FUSION_BUILDER_VERSION' ) : '';
	}

	/**
	 * Forget the cached element list.
	 */
	public static function reset(): void {
		self::$map = null;
	}

	/**
	 * Every element registered with Avada Builder: shortcode => module (name, params, …).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function map(): array {
		if ( null !== self::$map ) {
			return self::$map;
		}
		self::$map = array();
		if ( true !== self::available() ) {
			return self::$map;
		}
		try {
			if ( function_exists( 'awb_maybe_init_element_maps' ) ) {
				awb_maybe_init_element_maps();
			} elseif ( ! did_action( 'fusion_builder_before_init' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Avada Builder's own hook (versions before 3.15.6).
				do_action( 'fusion_builder_before_init' );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		$all = $GLOBALS['all_fusion_builder_elements'] ?? array();
		foreach ( is_array( $all ) ? $all : array() as $tag => $module ) {
			if ( is_array( $module ) ) {
				$tag               = is_string( $tag ) ? $tag : (string) ( $module['shortcode'] ?? '' );
				self::$map[ $tag ] = $module;
			}
		}
		unset( self::$map[''] );
		return self::$map;
	}

	/**
	 * Settings of one registered element, or null.
	 *
	 * @param string $tag Shortcode.
	 * @return array<string, mixed>|null
	 */
	public static function module( string $tag ): ?array {
		return self::map()[ $tag ] ?? null;
	}

	/**
	 * Parameters of an element by name.
	 *
	 * @param string $tag Shortcode.
	 * @return array<string, array<string, mixed>>
	 */
	public static function params( string $tag ): array {
		$out = array();
		foreach ( (array) ( self::module( $tag )['params'] ?? array() ) as $param ) {
			if ( is_array( $param ) && is_string( $param['param_name'] ?? null ) ) {
				$out[ $param['param_name'] ] = $param;
			}
		}
		return $out;
	}

	/**
	 * Elements switched on in Avada Builder's options (empty = all).
	 *
	 * @return list<string>
	 */
	public static function enabled(): array {
		$settings = get_option( 'fusion_builder_settings', array() );
		$elements = is_array( $settings ) ? ( $settings['fusion_elements'] ?? '' ) : '';
		return is_array( $elements ) ? array_values( array_map( 'strval', $elements ) ) : array();
	}

	/**
	 * Whether a name is a shortcode here (for names without a closing tag in the content).
	 *
	 * @param string $name Tag.
	 */
	public static function is_tag( string $name ): bool {
		return str_starts_with( $name, 'fusion_' ) || str_starts_with( $name, 'awb_' ) || isset( self::map()[ $name ] ) || shortcode_exists( $name );
	}

	/**
	 * Parse content with this site's shortcodes.
	 *
	 * @param string $content Content.
	 * @return list<array<string, mixed>>
	 * @throws ToolError When the content is too large.
	 */
	public static function parse( string $content ): array {
		if ( strlen( $content ) > Shortcodes::MAX_BYTES ) {
			throw new ToolError( 'This content is larger than ' . (int) ( Shortcodes::MAX_BYTES / 1000 ) . ' KB; KuDiagnostics does not edit layouts of that size.' );
		}
		return Shortcodes::parse( $content, array( self::class, 'is_tag' ) );
	}

	/**
	 * Which builder a post uses.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function builder( \WP_Post $post ): string {
		if ( 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) {
			return 'elementor';
		}
		if ( 'active' === get_post_meta( $post->ID, self::META_STATUS, true ) || str_contains( $post->post_content, '[fusion_builder_container' ) ) {
			return 'avada';
		}
		if ( 'true' === get_post_meta( $post->ID, WpBakery::META_STATUS, true ) || str_contains( $post->post_content, '[vc_row' ) ) {
			return 'wpbakery';
		}
		return 'none';
	}

	/**
	 * A post the Avada tools may work on.
	 *
	 * @param int  $id    Post id.
	 * @param bool $write Whether it will be changed.
	 * @throws ToolError When the post is missing, has no editor, is in the trash, or belongs to another builder.
	 */
	public static function post( int $id, bool $write = false ): \WP_Post {
		$post = Content::post( $id );
		if ( null === $post ) {
			throw new ToolError( 'No post or page with id ' . (int) $id . '.' );
		}
		if ( ! post_type_supports( $post->post_type, 'editor' ) ) {
			throw new ToolError( 'Posts of type "' . esc_html( $post->post_type ) . '" have no content editor, so there is no Avada layout.' );
		}
		if ( 'trash' === $post->post_status ) {
			throw new ToolError( 'This post is in the trash. Restore it first.' );
		}
		if ( $write ) {
			if ( 'fusion_tb_layout' === $post->post_type ) {
				throw new ToolError( 'This is an Avada Layout (which templates show where). Its conditions are managed in Avada > Layouts; change its sections (fusion_tb_section) instead. Nothing was changed.' );
			}
			$builder = self::builder( $post );
			if ( 'elementor' === $builder ) {
				throw new ToolError( 'This post is built with Elementor, not Avada. Use the kuduy-elementor-* tools; nothing was changed.' );
			}
			if ( 'wpbakery' === $builder ) {
				throw new ToolError( 'This post is built with WPBakery, not Avada. Use the kuduy-wpbakery-* tools; nothing was changed.' );
			}
		}
		return $post;
	}

	/**
	 * Refuse a change to a Library item or Layout section unless the caller confirmed the shared scope.
	 *
	 * @param \WP_Post             $post  Post.
	 * @param array<string, mixed> $input Tool input.
	 * @throws ToolError When the item is shared and not confirmed.
	 */
	public static function guard_shared( \WP_Post $post, array $input ): void {
		if ( ! in_array( $post->post_type, self::SHARED_TYPES, true ) || ! empty( $input['confirm_shared'] ) ) {
			return;
		}
		global $wpdb;
		$count = 'fusion_element' === $post->post_type
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a count of pages that embed this global element; no API offers it.
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status IN ('publish','draft','pending','private','future') AND post_content LIKE %s", '%' . $wpdb->esc_like( '[fusion_global id="' . $post->ID . '"' ) . '%' ) )
			: -1;
		$scope = $count >= 0 ? 'It is used on ' . $count . ' page(s) as a global element.' : 'It is shown on every page its Avada Layout applies to.';
		throw new ToolError( 'This is a shared Avada item (' . esc_html( $post->post_type ) . '): a change appears everywhere it is used. ' . esc_html( $scope ) . ' Call again with confirm_shared: true to change it. Nothing was changed.' );
	}

	/**
	 * Save a layout through WordPress (revision, content filter, Avada's own save hooks), mark the post as built with
	 * Avada Builder and verify what was stored.
	 *
	 * @param \WP_Post                   $post  Post.
	 * @param list<array<string, mixed>> $nodes New layout.
	 * @return array{post: \WP_Post, verified: bool}
	 * @throws ToolError When WordPress refuses the update.
	 */
	public static function save( \WP_Post $post, array $nodes ): array {
		$content = Shortcodes::serialize( $nodes );
		$result  = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => wp_slash( $content ),
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			throw new ToolError( 'WordPress did not save the page: ' . esc_html( $result->get_error_message() ) );
		}
		update_post_meta( $post->ID, self::META_STATUS, 'active' );
		clean_post_cache( $post->ID );
		$saved = get_post( $post->ID );
		if ( ! $saved instanceof \WP_Post ) {
			throw new ToolError( 'The page could not be read back after saving.' );
		}
		// WordPress's content filter may change markup for accounts without unfiltered_html; that is reported, not hidden.
		return array(
			'post'     => $saved,
			'verified' => $saved->post_content === $content,
		);
	}

	/**
	 * Before/after of a change: the part of the content that differs, with a little context.
	 *
	 * @param string $before Old content.
	 * @param string $after  New content.
	 * @return array{changed: bool, before: string, after: string, bytes_before: int, bytes_after: int}
	 */
	public static function diff( string $before, string $after ): array {
		$changed = $before !== $after;
		$start   = 0;
		$max     = min( strlen( $before ), strlen( $after ) );
		while ( $start < $max && $before[ $start ] === $after[ $start ] ) {
			++$start;
		}
		$end_a = strlen( $before );
		$end_b = strlen( $after );
		while ( $end_a > $start && $end_b > $start && $before[ $end_a - 1 ] === $after[ $end_b - 1 ] ) {
			--$end_a;
			--$end_b;
		}
		$from = max( 0, $start - 80 );
		$cut  = static fn( string $s, int $a, int $b ) => ( $a > 0 ? '…' : '' ) . substr( $s, $a, min( 2000, $b - $a ) ) . ( $b - $a > 2000 || $b < strlen( $s ) ? '…' : '' );
		return array(
			'changed'      => $changed,
			'before'       => $changed ? $cut( $before, $from, min( strlen( $before ), $end_a + 80 ) ) : '',
			'after'        => $changed ? $cut( $after, $from, min( strlen( $after ), $end_b + 80 ) ) : '',
			'bytes_before' => strlen( $before ),
			'bytes_after'  => strlen( $after ),
		);
	}

	/**
	 * Versions and combination for kuduy-avada-status.
	 *
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$theme   = wp_get_theme( 'Avada' );
		$active  = in_array( 'Avada', array( get_template(), get_stylesheet() ), true );
		$builder = true === self::available();
		$combo   = match ( true ) {
			$active && $builder  => 'theme_and_builder',
			$active              => 'theme_without_builder',
			$builder             => 'builder_with_other_theme',
			default              => 'not_installed',
		};
		$settings   = get_option( 'fusion_builder_settings', array() );
		$post_types = is_array( $settings ) && isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) ? array_values( array_map( 'strval', $settings['post_types'] ) ) : array();
		return array(
			'combination'    => $combo,
			'theme'          => array(
				'installed' => $theme->exists(),
				'active'    => $active,
				'version'   => $theme->exists() ? (string) $theme->get( 'Version' ) : '',
				'child'     => $active && 'Avada' !== get_stylesheet() ? get_stylesheet() : '',
			),
			'builder'        => array(
				'active'  => $builder,
				'version' => self::version(),
			),
			'core'           => array(
				'active'  => defined( 'FUSION_CORE_VERSION' ),
				'version' => defined( 'FUSION_CORE_VERSION' ) ? (string) constant( 'FUSION_CORE_VERSION' ) : '',
			),
			'post_types'     => $post_types ? $post_types : array( 'all post types with an editor (Avada Builder default)' ),
			'elements'       => array(
				'registered' => count( self::map() ),
				'enabled'    => self::enabled() ? count( self::enabled() ) : 'all',
			),
			'layouts'        => post_type_exists( 'fusion_tb_layout' ),
			'library'        => post_type_exists( 'fusion_element' ),
			'forms'          => post_type_exists( 'fusion_form' ),
			'woocommerce'    => defined( 'WC_VERSION' ),
			'other_builders' => array_values(
				array_filter(
					array(
						defined( 'ELEMENTOR_VERSION' ) ? 'elementor' : '',
						defined( 'WPB_VC_VERSION' ) ? 'wpbakery' : '',
					)
				)
			),
		);
	}
}
