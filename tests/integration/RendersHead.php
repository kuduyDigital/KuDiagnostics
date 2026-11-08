<?php
/**
 * Test helper: the `<head>` a visitor gets for a post, produced in-process (Playground has no loopback HTTP).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

trait RendersHead {

	/**
	 * Hooks as they were before any page view in this process (a visitor request starts from this state).
	 *
	 * @var array<string, \WP_Hook>|null
	 */
	private static ?array $pristine_hooks = null;

	/**
	 * Call from setUpBeforeClass(), before the tests call any tool.
	 */
	private static function remember_hooks(): void {
		global $wp_filter;
		self::$pristine_hooks = self::copy_hooks( (array) $wp_filter );
	}

	/**
	 * Set up the front-end main query for one post, fire `wp` like WP::main(), then capture wp_head().
	 * Runs from the remembered hook state and resets the per-request caches SEO plugins keep, as a new
	 * visitor request would; afterwards the test's own state is put back. Test-only: KUDUY's code never does this.
	 *
	 * @param int $id Post id.
	 */
	private function head( int $id ): string {
		global $wp, $wp_query, $wp_the_query, $post, $wp_filter;
		$this->assertNotNull( self::$pristine_hooks, 'call remember_hooks() in setUpBeforeClass()' );
		$saved = array( $wp_query, $wp_the_query, $post, get_current_user_id(), self::copy_hooks( (array) $wp_filter ) );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulates a fresh request.
		$wp_filter = self::copy_hooks( (array) self::$pristine_hooks );
		self::reset_plugin_caches();
		// Block themes print <title> from the template loader (locate_block_template()), not from theme support.
		if ( wp_is_block_theme() && ! current_theme_supports( 'title-tag' ) ) {
			add_action( 'wp_head', '_block_template_render_title_tag', 1 );
		}
		wp_set_current_user( 0 );
		$query        = new \WP_Query(
			array(
				'p'         => $id,
				'post_type' => get_post_type( $id ),
			)
		);
		$wp_the_query = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulates a front-end request.
		$wp_query     = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- same.
		$query->the_post();
		do_action_ref_array( 'wp', array( &$wp ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, as WP::main() fires it.
		ob_start();
		do_action( 'wp_head' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		$html = (string) ob_get_clean();
		wp_reset_postdata();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restores the globals saved above.
		[ $wp_query, $wp_the_query, $post, $user, $wp_filter ] = $saved;
		wp_set_current_user( $user );
		self::reset_plugin_caches();
		return $html;
	}

	/**
	 * Run kuduy-seo-verify with this head served as the live page (Playground has no loopback HTTP).
	 * Call it with the head from the class's first head() render: some SEO plugins (AIOSEO's title, SEOPress's
	 * head output) set themselves up once per process, so a second in-process render is not a fresh request.
	 *
	 * @param int                  $id     Post id.
	 * @param string               $head   Head HTML.
	 * @param array<string, mixed> $expect Expected values.
	 * @return array<string, mixed>
	 */
	private function verify_live( int $id, string $head, array $expect ): array {
		$body   = '<!doctype html><html><head>' . $head . '</head><body></body></html>';
		$filter = static function ( mixed $pre, array $args, string $url ) use ( $body ): mixed {
			if ( ! str_starts_with( $url, home_url() ) ) {
				return $pre;
			}
			return array(
				'headers'  => array(),
				'body'     => $body,
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );
		try {
			return $this->ok(
				'kuduy-seo-verify',
				array(
					'post_id' => $id,
					'expect'  => $expect,
				)
			);
		} finally {
			remove_filter( 'pre_http_request', $filter, 10 );
		}
	}

	/**
	 * Copy of a hook table (WP_Hook objects are mutable).
	 *
	 * @param array<string, \WP_Hook> $hooks Hooks.
	 * @return array<string, \WP_Hook>
	 */
	private static function copy_hooks( array $hooks ): array {
		return array_map( static fn( \WP_Hook $hook ): \WP_Hook => clone $hook, $hooks );
	}

	/**
	 * Per-request caches of SEO plugins.
	 */
	private static function reset_plugin_caches(): void {
		// Rank Math: Paper::get() keeps the first page object (includes/frontend/paper/class-paper.php); Metadata
		// keeps each post's meta values once read (includes/class-metadata.php).
		if ( class_exists( \RankMath\Paper\Paper::class ) ) {
			( new \ReflectionProperty( \RankMath\Paper\Paper::class, 'instance' ) )->setValue( null, null );
		}
		if ( class_exists( \RankMath\Metadata::class ) ) {
			( new \ReflectionProperty( \RankMath\Metadata::class, 'objects' ) )->setValue( null, array() );
		}
		// Yoast: the meta tags context memoizer keeps the page context of the request.
		if ( function_exists( 'YoastSEO' ) ) {
			$memoizer = YoastSEO()->classes->get( 'Yoast\WP\SEO\Memoizers\Meta_Tags_Context_Memoizer' );
			if ( is_object( $memoizer ) && method_exists( $memoizer, 'clear' ) ) {
				$memoizer->clear();
			}
		}
	}

	/**
	 * Content of `<meta name|property="…" content="…">` in the head, or null.
	 *
	 * @param string $html Head HTML.
	 * @param string $name name or property value.
	 */
	private static function meta_tag( string $html, string $name ): ?string {
		$quoted = preg_quote( $name, '/' );
		if ( preg_match( '/<meta\s+(?:name|property)=["\']' . $quoted . '["\']\s+content=["\']([^"\']*)["\']/i', $html, $m ) ) {
			return html_entity_decode( $m[1], ENT_QUOTES );
		}
		if ( preg_match( '/<meta\s+content=["\']([^"\']*)["\']\s+(?:name|property)=["\']' . $quoted . '["\']/i', $html, $m ) ) {
			return html_entity_decode( $m[1], ENT_QUOTES );
		}
		return null;
	}
}
