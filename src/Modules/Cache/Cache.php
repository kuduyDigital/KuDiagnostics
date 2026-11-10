<?php
/**
 * Cache plugins KuDiagnostics can purge, through each plugin's documented purge API (decision D19 A).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Cache;

/**
 * Detection and purge calls per plugin (RESEARCH.md §10d). Each purge uses the function or action the plugin
 * documents for other plugins: LiteSpeed Cache's litespeed_purge_* actions, W3 Total Cache's w3tc_flush_*(),
 * WP Super Cache's wp_cache_clear_cache() / wpsc_delete_post_cache() / wpsc_delete_url_cache(), WP Fastest Cache's
 * wpfc_clear_all_cache() / wpfc_clear_post_cache_by_id(), WP Rocket's rocket_clean_*() (docs only, UNVERIFIED).
 */
final class Cache {

	/**
	 * Plugins: id => name.
	 */
	public const PLUGINS = array(
		'litespeed'     => 'LiteSpeed Cache',
		'w3tc'          => 'W3 Total Cache',
		'super-cache'   => 'WP Super Cache',
		'fastest-cache' => 'WP Fastest Cache',
		'rocket'        => 'WP Rocket',
	);

	/**
	 * Whether a plugin is loaded.
	 *
	 * @param string $id Plugin id.
	 */
	public static function active( string $id ): bool {
		return match ( $id ) {
			'litespeed'     => defined( 'LSCWP_V' ),
			'w3tc'          => function_exists( 'w3tc_flush_all' ),
			'super-cache'   => function_exists( 'wp_cache_clear_cache' ),
			'fastest-cache' => function_exists( 'wpfc_clear_all_cache' ),
			'rocket'        => function_exists( 'rocket_clean_domain' ),
			default         => false,
		};
	}

	/**
	 * Version of a plugin, or ''.
	 *
	 * @param string $id Plugin id.
	 */
	public static function version( string $id ): string {
		$constant = array(
			'litespeed'   => 'LSCWP_V',
			'w3tc'        => 'W3TC_VERSION',
			'super-cache' => 'WPSC_VERSION_ID',
			'rocket'      => 'WP_ROCKET_VERSION',
		)[ $id ] ?? '';
		if ( '' !== $constant && defined( $constant ) ) {
			return (string) constant( $constant );
		}
		if ( 'fastest-cache' === $id && defined( 'WPFC_WP_PLUGIN_DIR' ) ) {
			$data = get_file_data( WPFC_WP_PLUGIN_DIR . '/wp-fastest-cache/wpFastestCache.php', array( 'Version' => 'Version' ) );
			return (string) ( $data['Version'] ?? '' );
		}
		return '';
	}

	/**
	 * Ids of loaded cache plugins.
	 *
	 * @return list<string>
	 */
	public static function detected(): array {
		return array_values( array_filter( array_keys( self::PLUGINS ), array( self::class, 'active' ) ) );
	}

	/**
	 * Purge one plugin's page cache: everything, one post (its URLs), or one URL.
	 *
	 * @param string $id     Plugin id.
	 * @param string $target all | post | url.
	 * @param int    $post   Post id (post).
	 * @param string $url    URL (url).
	 */
	public static function purge( string $id, string $target, int $post = 0, string $url = '' ): void {
		switch ( $id . ':' . $target ) {
			case 'litespeed:all':
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's public purge API.
				do_action( 'litespeed_purge_all', 'KuDiagnostics' );
				break;
			case 'litespeed:post':
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's public purge API.
				do_action( 'litespeed_purge_post', $post );
				break;
			case 'litespeed:url':
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's public purge API.
				do_action( 'litespeed_purge_url', $url );
				break;
			case 'w3tc:all':
				w3tc_flush_all();
				break;
			case 'w3tc:post':
				w3tc_flush_post( $post );
				break;
			case 'w3tc:url':
				w3tc_flush_url( $url );
				break;
			case 'super-cache:all':
				wp_cache_clear_cache();
				break;
			case 'super-cache:post':
				wpsc_delete_post_cache( $post );
				break;
			case 'super-cache:url':
				wpsc_delete_url_cache( $url );
				break;
			case 'fastest-cache:all':
				wpfc_clear_all_cache( true );
				break;
			case 'fastest-cache:post':
				wpfc_clear_post_cache_by_id( $post );
				break;
			case 'fastest-cache:url':
				$found = url_to_postid( $url );
				if ( $found > 0 ) {
					wpfc_clear_post_cache_by_id( $found );
				} else {
					// WP Fastest Cache has no per-URL purge for addresses that are not posts.
					wpfc_clear_all_cache( false );
				}
				break;
			case 'rocket:all':
				rocket_clean_domain();
				break;
			case 'rocket:post':
				rocket_clean_post( $post );
				break;
			case 'rocket:url':
				rocket_clean_files( array( $url ) );
				break;
		}//end switch
	}
}
