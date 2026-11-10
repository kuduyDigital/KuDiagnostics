<?php
/**
 * Shared WPBakery Page Builder access: availability, registered elements, posts, saving.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\WpBakery;

use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * WPBakery keeps a layout as shortcodes in post_content, the "built with WPBakery" flag in the post meta
 * _wpb_vc_js_status and the CSS of its Design Options in _wpb_shortcodes_custom_css. Registered elements come from
 * WPBMap (filled by vc_map() of WPBakery, themes and add-ons). WPBakery is a paid plugin: these names are taken from
 * its public documentation and long-standing behaviour and are UNVERIFIED against a licensed copy (RESEARCH.md §10c).
 * Every call into the plugin is guarded, so a missing function is reported, not fatal.
 */
final class WpBakery {

	/**
	 * Post meta: 'true' while the post is edited with WPBakery.
	 */
	public const META_STATUS = '_wpb_vc_js_status';

	/**
	 * Post meta: CSS of the Design Options used on the post.
	 */
	public const META_CSS = '_wpb_shortcodes_custom_css';

	/**
	 * Elements whose content is stored as base64( rawurlencode( html ) ).
	 */
	public const RAW_TAGS = array( 'vc_raw_html', 'vc_raw_js' );

	/**
	 * Registered elements, per request.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $map = null;

	/**
	 * True when WPBakery is loaded, else the reason for the tool error.
	 */
	public static function available(): bool|string {
		return defined( 'WPB_VC_VERSION' ) && class_exists( '\WPBMap' ) ? true : 'WPBakery Page Builder is not active on this site.';
	}

	/**
	 * WPBakery version, or ''.
	 */
	public static function version(): string {
		return defined( 'WPB_VC_VERSION' ) ? (string) constant( 'WPB_VC_VERSION' ) : '';
	}

	/**
	 * Forget the cached element list (tests, and after plugins register more elements).
	 */
	public static function reset(): void {
		self::$map = null;
	}

	/**
	 * Every element registered with WPBakery on this site: tag => settings (name, category, params, …).
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
		// A WPBakery version without one of these methods throws an Error, which is caught: the tools then report
		// no registered elements instead of breaking the request.
		try {
			// WPBakery maps its elements lazily; outside its own screens they may not be loaded yet.
			\WPBMap::addAllMappedShortcodes();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		try {
			$all = \WPBMap::getAllShortCodes();
		} catch ( \Throwable $e ) {
			$all = array();
		}
		foreach ( is_array( $all ) ? $all : array() as $tag => $settings ) {
			if ( is_string( $tag ) && is_array( $settings ) ) {
				self::$map[ $tag ] = $settings;
			}
		}
		return self::$map;
	}

	/**
	 * Settings of one registered element, or null.
	 *
	 * @param string $tag Shortcode tag.
	 * @return array<string, mixed>|null
	 */
	public static function settings( string $tag ): ?array {
		return self::map()[ $tag ] ?? null;
	}

	/**
	 * One parameter definition of an element, or null.
	 *
	 * @param string $tag  Shortcode tag.
	 * @param string $name Parameter name.
	 * @return array<string, mixed>|null
	 */
	public static function param( string $tag, string $name ): ?array {
		foreach ( (array) ( self::settings( $tag )['params'] ?? array() ) as $param ) {
			if ( is_array( $param ) && ( $param['param_name'] ?? null ) === $name ) {
				return $param;
			}
		}
		return null;
	}

	/**
	 * Whether a name is a shortcode on this site (for names that have no closing tag in the content).
	 *
	 * @param string $name Tag name.
	 */
	public static function is_tag( string $name ): bool {
		return str_starts_with( $name, 'vc_' ) || isset( self::map()[ $name ] ) || shortcode_exists( $name );
	}

	/**
	 * Parse content with this site's shortcodes.
	 *
	 * @param string $content Shortcode content.
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
	 * A post the WPBakery tools may work on.
	 *
	 * @param int  $id    Post id.
	 * @param bool $write Whether it will be changed.
	 * @throws ToolError When the post is missing, of a type without an editor, or built with Elementor.
	 */
	public static function post( int $id, bool $write = false ): \WP_Post {
		$post = Content::post( $id );
		if ( null === $post ) {
			throw new ToolError( 'No post or page with id ' . (int) $id . '.' );
		}
		if ( ! post_type_supports( $post->post_type, 'editor' ) ) {
			throw new ToolError( 'Posts of type "' . esc_html( $post->post_type ) . '" have no content editor, so there is no WPBakery layout.' );
		}
		if ( 'trash' === $post->post_status ) {
			throw new ToolError( 'This post is in the trash. Restore it first.' );
		}
		if ( $write && 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) {
			throw new ToolError( 'This post is built with Elementor, not WPBakery. Use the kuduy-elementor-* tools; nothing was changed.' );
		}
		return $post;
	}

	/**
	 * Which builder a post uses: wpbakery, elementor or none.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function builder( \WP_Post $post ): string {
		if ( 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) {
			return 'elementor';
		}
		return 'true' === get_post_meta( $post->ID, self::META_STATUS, true ) || str_contains( $post->post_content, '[vc_row' ) || str_contains( $post->post_content, '[vc_section' ) ? 'wpbakery' : 'none';
	}

	/**
	 * Fingerprint of a post's content, to notice edits made in between.
	 *
	 * @param string $content Post content.
	 */
	public static function hash( string $content ): string {
		return substr( md5( $content ), 0, 12 );
	}

	/**
	 * Refuse when someone else has the post open, or it changed since the caller read it.
	 *
	 * @param \WP_Post             $post  Post.
	 * @param array<string, mixed> $input Tool input (if_hash, ignore_lock).
	 * @throws ToolError When the post is locked or changed.
	 */
	public static function guard_concurrent( \WP_Post $post, array $input ): void {
		$expected = (string) ( $input['if_hash'] ?? '' );
		if ( '' !== $expected && ! hash_equals( self::hash( $post->post_content ), $expected ) ) {
			throw new ToolError( 'The page changed since you read it (hash is now ' . esc_html( self::hash( $post->post_content ) ) . '). Read the tree again; nothing was changed.' );
		}
		if ( ! empty( $input['ignore_lock'] ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/post.php';
		$user_id = wp_check_post_lock( $post->ID );
		if ( $user_id ) {
			$user = get_userdata( (int) $user_id );
			throw new ToolError( esc_html( $user ? $user->display_name : 'Another user' ) . ' is editing this page in WordPress right now, and saving here would overwrite their work. Ask them to close the editor, or call again with ignore_lock: true. Nothing was changed.' );
		}
	}

	/**
	 * The CSS of every Design Options attribute in a layout, as WPBakery stores it for the page.
	 *
	 * @param list<array<string, mixed>> $nodes Nodes.
	 */
	public static function custom_css( array $nodes ): string {
		$css = '';
		foreach ( $nodes as $node ) {
			if ( 'element' !== $node['type'] ) {
				continue;
			}
			foreach ( $node['atts'] as $att ) {
				$name = (string) $att['name'];
				$type = (string) ( self::param( $node['tag'], $name )['type'] ?? '' );
				if ( ( 'css_editor' === $type || ( '' === $type && 'css' === $name ) ) && str_starts_with( ltrim( $att['value'] ), '.vc_custom_' ) ) {
					$css .= $att['value'];
				}
			}
			$css .= self::custom_css( $node['children'] );
		}
		return $css;
	}

	/**
	 * Raw HTML / JavaScript elements of a layout, as stored.
	 *
	 * @param list<array<string, mixed>> $nodes Nodes.
	 * @return list<string>
	 */
	private static function raw_elements( array $nodes ): array {
		$out = array();
		foreach ( $nodes as $node ) {
			if ( 'element' !== $node['type'] ) {
				continue;
			}
			if ( in_array( $node['tag'], self::RAW_TAGS, true ) ) {
				$out[] = Shortcodes::serialize( $node['children'] );
			}
			array_push( $out, ...self::raw_elements( $node['children'] ) );
		}
		return $out;
	}

	/**
	 * Refuse new or changed Raw HTML / Raw JS elements from accounts that may not post unfiltered HTML. (Their content
	 * is base64 text, which WordPress's own content filter cannot see into.)
	 *
	 * @param list<array<string, mixed>> $nodes New layout.
	 * @param list<array<string, mixed>> $old   Layout before the change.
	 * @throws ToolError When the account lacks unfiltered_html.
	 */
	public static function guard_raw( array $nodes, array $old = array() ): void {
		if ( array_diff( self::raw_elements( $nodes ), self::raw_elements( $old ) ) && ! current_user_can( 'unfiltered_html' ) ) {
			throw new ToolError( 'Adding or changing a Raw HTML or Raw JS element needs the unfiltered_html permission, which your account does not have. Nothing was changed.' );
		}
	}

	/**
	 * Save a layout to a post through WordPress (revision, kses for users without unfiltered_html, save hooks), mark the
	 * post as built with WPBakery and refresh the page's Design Options CSS.
	 *
	 * @param \WP_Post                   $post  Post.
	 * @param list<array<string, mixed>> $nodes New layout.
	 * @return \WP_Post The saved post.
	 * @throws ToolError When WordPress refuses the update or raw HTML/JS is added without permission.
	 */
	public static function save( \WP_Post $post, array $nodes ): \WP_Post {
		self::guard_raw( $nodes, self::parse( $post->post_content ) );
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
		self::after_save( $post->ID );
		$saved = get_post( $post->ID );
		if ( ! $saved instanceof \WP_Post ) {
			throw new ToolError( 'The page could not be read back after saving.' );
		}
		return $saved;
	}

	/**
	 * Meta WPBakery keeps next to the content.
	 *
	 * @param int $post_id Post id.
	 */
	public static function after_save( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		update_post_meta( $post_id, self::META_STATUS, 'true' );
		$css = self::custom_css( self::parse( $post->post_content ) );
		if ( '' === $css ) {
			delete_post_meta( $post_id, self::META_CSS );
		} else {
			update_post_meta( $post_id, self::META_CSS, wp_slash( wp_strip_all_tags( $css ) ) );
		}
		// Let WPBakery rebuild its own copy when it offers the method (same result as saving in its editor).
		foreach ( array( 'wpbakery', 'visual_composer' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				continue;
			}
			try {
				$base = $function();
				if ( is_object( $base ) && is_callable( array( $base, 'buildShortcodesCustomCss' ) ) ) {
					$base->buildShortcodesCustomCss( $post_id );
				}
			} catch ( \Throwable $e ) {
				// Our own meta above already holds the CSS.
				unset( $e );
			}
			break;
		}
	}

	/**
	 * Links to edit and view a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array{edit_url: string, link: string}
	 */
	public static function links( \WP_Post $post ): array {
		return array(
			'edit_url' => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
			'link'     => (string) get_permalink( $post ),
		);
	}
}
