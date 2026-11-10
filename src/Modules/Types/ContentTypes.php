<?php
/**
 * Post types and taxonomies defined through KuDiagnostics (decision D22 A).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Types;

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Definitions are stored in one option and registered with core's register_post_type() / register_taxonomy() on
 * every request (init, priority 5). Only a safe set of arguments is accepted; capabilities follow posts
 * (capability_type post), so access works like for posts. Removing a definition never deletes content: the posts and
 * terms stay in the database and come back if the type is defined again. If KuDiagnostics is deactivated the types are not
 * registered (their content stays).
 */
final class ContentTypes {

	public const OPTION = 'kuduy_mcp_content_types';

	/**
	 * Names WordPress reserves for query variables (developer.wordpress.org, register_post_type / register_taxonomy).
	 */
	private const RESERVED = array( 'action', 'attachment', 'attachment_id', 'author', 'author_name', 'calendar', 'cat', 'category', 'category__and', 'category__in', 'category__not_in', 'category_name', 'comments_per_page', 'comments_popup', 'custom', 'customize_messenger_channel', 'customized', 'cpage', 'day', 'debug', 'embed', 'error', 'exact', 'feed', 'fields', 'hour', 'link_category', 'm', 'minute', 'monthnum', 'more', 'name', 'nav_menu', 'nonce', 'nopaging', 'offset', 'order', 'orderby', 'p', 'page', 'page_id', 'paged', 'pagename', 'pb', 'perm', 'post', 'post__in', 'post__not_in', 'post_format', 'post_mime_type', 'post_status', 'post_tag', 'post_type', 'posts', 'posts_per_archive_page', 'posts_per_page', 'preview', 'robots', 's', 'search', 'second', 'sentence', 'showposts', 'static', 'status', 'subpost', 'subpost_id', 'tag', 'tag__and', 'tag__in', 'tag__not_in', 'tag_id', 'tag_slug__and', 'tag_slug__in', 'taxonomy', 'tb', 'term', 'terms', 'theme', 'title', 'type', 'types', 'w', 'withcomments', 'withoutcomments', 'year', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face' );

	/**
	 * Supports values core knows.
	 */
	public const SUPPORTS = array( 'title', 'editor', 'author', 'thumbnail', 'excerpt', 'trackbacks', 'custom-fields', 'comments', 'revisions', 'page-attributes', 'post-formats' );

	/**
	 * Stored definitions.
	 *
	 * @return array{post_types: array<string, array<string, mixed>>, taxonomies: array<string, array<string, mixed>>}
	 */
	public static function stored(): array {
		$value = get_option( self::OPTION, array() );
		$value = is_array( $value ) ? $value : array();
		return array(
			'post_types' => (array) ( $value['post_types'] ?? array() ),
			'taxonomies' => (array) ( $value['taxonomies'] ?? array() ),
		);
	}

	/**
	 * Save definitions.
	 *
	 * @param array{post_types: array<string, array<string, mixed>>, taxonomies: array<string, array<string, mixed>>} $defs Definitions.
	 */
	public static function save( array $defs ): void {
		update_option( self::OPTION, $defs, true );
	}

	/**
	 * Register every stored definition (init, priority 5).
	 */
	public static function register(): void {
		$defs = self::stored();
		foreach ( $defs['post_types'] as $slug => $def ) {
			if ( ! post_type_exists( (string) $slug ) ) {
				register_post_type( (string) $slug, self::post_type_args( (array) $def ) );
			}
		}
		foreach ( $defs['taxonomies'] as $slug => $def ) {
			if ( ! taxonomy_exists( (string) $slug ) ) {
				register_taxonomy( (string) $slug, array_values( (array) ( $def['object_type'] ?? array() ) ), self::taxonomy_args( (array) $def ) );
			}
		}
	}

	/**
	 * Hook registration (or run it now when init already ran).
	 */
	public static function boot(): void {
		if ( did_action( 'init' ) ) {
			self::register();
		} else {
			add_action( 'init', array( self::class, 'register' ), 5 );
		}
	}

	/**
	 * Refuse a slug that is reserved, malformed or already used by someone else.
	 *
	 * @param string $slug Slug.
	 * @param int    $max  Maximum length (20 for post types, 32 for taxonomies).
	 * @param string $kind post_types | taxonomies.
	 * @throws ToolError When it cannot be used.
	 */
	public static function assert_slug( string $slug, int $max, string $kind ): void {
		if ( ! preg_match( '/^[a-z0-9_-]{1,' . $max . '}$/', $slug ) ) {
			throw new ToolError( 'The key must be 1–' . (int) $max . ' characters: lowercase letters, digits, "_" or "-".' );
		}
		if ( in_array( $slug, self::RESERVED, true ) || str_starts_with( $slug, 'wp_' ) ) {
			throw new ToolError( '"' . esc_html( $slug ) . '" is reserved by WordPress.' );
		}
		$ours   = isset( self::stored()[ $kind ][ $slug ] );
		$exists = 'post_types' === $kind ? post_type_exists( $slug ) : taxonomy_exists( $slug );
		if ( $exists && ! $ours ) {
			throw new ToolError( '"' . esc_html( $slug ) . '" is already registered by WordPress, the theme or another plugin.' );
		}
	}

	/**
	 * Core arguments for a stored post type definition.
	 *
	 * @param array<string, mixed> $def Definition.
	 * @return array<string, mixed>
	 */
	public static function post_type_args( array $def ): array {
		$args = array(
			'labels'          => array(
				'name'          => (string) ( $def['plural'] ?? '' ),
				'singular_name' => (string) ( $def['singular'] ?? '' ),
			),
			'public'          => (bool) ( $def['public'] ?? true ),
			'hierarchical'    => (bool) ( $def['hierarchical'] ?? false ),
			'has_archive'     => (bool) ( $def['has_archive'] ?? false ),
			'show_in_rest'    => true,
			'supports'        => array_values( array_intersect( (array) ( $def['supports'] ?? array( 'title', 'editor' ) ), self::SUPPORTS ) ),
			'menu_icon'       => (string) ( $def['menu_icon'] ?? 'dashicons-admin-post' ),
			'capability_type' => 'post',
			'map_meta_cap'    => true,
			'taxonomies'      => array_values( array_map( 'strval', (array) ( $def['taxonomies'] ?? array() ) ) ),
		);
		if ( ! empty( $def['rewrite_slug'] ) ) {
			$args['rewrite'] = array( 'slug' => (string) $def['rewrite_slug'] );
		}
		return $args;
	}

	/**
	 * Core arguments for a stored taxonomy definition.
	 *
	 * @param array<string, mixed> $def Definition.
	 * @return array<string, mixed>
	 */
	public static function taxonomy_args( array $def ): array {
		$args = array(
			'labels'            => array(
				'name'          => (string) ( $def['plural'] ?? '' ),
				'singular_name' => (string) ( $def['singular'] ?? '' ),
			),
			'public'            => (bool) ( $def['public'] ?? true ),
			'hierarchical'      => (bool) ( $def['hierarchical'] ?? false ),
			'show_in_rest'      => true,
			'show_admin_column' => true,
		);
		if ( ! empty( $def['rewrite_slug'] ) ) {
			$args['rewrite'] = array( 'slug' => (string) $def['rewrite_slug'] );
		}
		return $args;
	}
}
