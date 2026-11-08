<?php
/**
 * SEOPress adapter.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Seo;

use Kuduy\Mcp\Mcp\ToolError;

/**
 * SEOPress 10.4 (RESEARCH.md §9). Title, description, robots, canonical and social through SEOPress's abilities
 * (`seopress/get-post-…` / `seopress/update-post-…`, which run its own REST controllers and refuse roles blocked
 * in Advanced > Security); target keywords through its REST route `seopress/v1/posts/{id}/target-keywords` (no
 * ability exists for them). SEOPress has no per-post "force index", no noarchive and no cornerstone flag, so
 * robots.noindex false means the same as null (post type default) and those fields are not offered.
 */
final class SeopressAdapter implements SeoAdapter {

	/**
	 * Robots flags => SEOPress meta key ('yes' = the "no…" directive).
	 */
	private const ROBOTS = array(
		'robots.noindex'      => '_seopress_robots_index',
		'robots.nofollow'     => '_seopress_robots_follow',
		'robots.nosnippet'    => '_seopress_robots_snippet',
		'robots.noimageindex' => '_seopress_robots_imageindex',
	);

	private const CANONICAL = '_seopress_robots_canonical';

	/**
	 * Social fields => SEOPress meta key.
	 */
	private const SOCIAL = array(
		'og.title'            => '_seopress_social_fb_title',
		'og.description'      => '_seopress_social_fb_desc',
		'twitter.title'       => '_seopress_social_twitter_title',
		'twitter.description' => '_seopress_social_twitter_desc',
	);

	private const KEYWORDS = '_seopress_analysis_target_kw';

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'seopress';
	}

	/**
	 * {@inheritDoc}
	 */
	public function ready(): bool|string {
		return function_exists( 'wp_get_ability' ) && wp_get_ability( 'seopress/update-post-title-description' )
			? true
			: 'SEOPress is active but its abilities are not registered (needs WordPress 6.9+ and SEOPress 10.4+).';
	}

	/**
	 * {@inheritDoc}
	 */
	public function fields(): array {
		return array_merge( array( 'title', 'description', 'focus_keyword', 'additional_keywords', 'canonical' ), array_keys( self::ROBOTS ), array_keys( self::SOCIAL ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post id.
	 */
	public function stored( int $post_id ): array {
		$basics   = Seo::ability( 'seopress/get-post-title-description', array( 'post_id' => $post_id ) );
		$values   = self::values( Seo::ability( 'seopress/get-post-robots-settings', array( 'post_id' => $post_id ) ) )
			+ self::values( Seo::ability( 'seopress/get-post-social-settings', array( 'post_id' => $post_id ) ) );
		$keywords = array_values( array_filter( array_map( 'trim', explode( ',', (string) get_post_meta( $post_id, self::KEYWORDS, true ) ) ), static fn( string $k ): bool => '' !== $k ) );
		$out      = array(
			'title'               => Seo::text( $basics['title'] ?? null ),
			'description'         => Seo::text( $basics['description'] ?? null ),
			'focus_keyword'       => $keywords[0] ?? null,
			'additional_keywords' => array_slice( $keywords, 1 ),
			'canonical'           => Seo::text( $values[ self::CANONICAL ] ?? null ),
		);
		foreach ( self::ROBOTS as $field => $key ) {
			$yes           = 'yes' === ( $values[ $key ] ?? '' );
			$out[ $field ] = 'robots.noindex' === $field ? ( $yes ? true : null ) : $yes;
		}
		foreach ( self::SOCIAL as $field => $key ) {
			$out[ $field ] = Seo::text( $values[ $key ] ?? null );
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * SEOPress builds its output only during a front-end request; use kuduy-seo-verify for what visitors get.
	 *
	 * @param int $post_id Post id.
	 */
	public function rendered( int $post_id ): ?array {
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $patch Flat patch.
	 */
	public function missing_caps( array $patch ): array {
		$missing = array();
		if ( function_exists( 'seopress_metabox_role_is_blocked' ) ) {
			if ( seopress_metabox_role_is_blocked( 'GLOBAL' ) ) {
				$missing[] = 'SEOPress SEO metabox (blocked for your role in SEO > Advanced > Security)';
			}
			$keywords = array_key_exists( 'focus_keyword', $patch ) || array_key_exists( 'additional_keywords', $patch );
			if ( $keywords && seopress_metabox_role_is_blocked( 'CONTENT_ANALYSIS' ) ) {
				$missing[] = 'SEOPress content analysis (blocked for your role in SEO > Advanced > Security)';
			}
		}
		return $missing;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $post_id Post id.
	 * @param array<string, mixed> $patch   Flat patch.
	 */
	public function write( int $post_id, array $patch ): void {
		$basics = array( 'post_id' => $post_id );
		foreach ( array( 'title', 'description' ) as $field ) {
			if ( array_key_exists( $field, $patch ) ) {
				$basics[ $field ] = (string) Seo::text( $patch[ $field ] );
			}
		}
		if ( count( $basics ) > 1 ) {
			Seo::ability( 'seopress/update-post-title-description', $basics );
		}
		$robots = array();
		foreach ( self::ROBOTS as $field => $key ) {
			if ( array_key_exists( $field, $patch ) ) {
				$robots[ $key ] = $patch[ $field ] ? 'yes' : '';
			}
		}
		if ( array_key_exists( 'canonical', $patch ) ) {
			$robots[ self::CANONICAL ] = (string) Seo::text( $patch['canonical'] );
		}
		if ( $robots ) {
			Seo::ability(
				'seopress/update-post-robots-settings',
				array(
					'post_id'  => $post_id,
					'settings' => $robots,
				)
			);
		}
		$social = array();
		foreach ( self::SOCIAL as $field => $key ) {
			if ( array_key_exists( $field, $patch ) ) {
				$social[ $key ] = (string) Seo::text( $patch[ $field ] );
			}
		}
		if ( $social ) {
			Seo::ability(
				'seopress/update-post-social-settings',
				array(
					'post_id'  => $post_id,
					'settings' => $social,
				)
			);
		}
		if ( array_key_exists( 'focus_keyword', $patch ) || array_key_exists( 'additional_keywords', $patch ) ) {
			$this->keywords( $post_id, $patch );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post id.
	 */
	public function snapshot( int $post_id ): array {
		return $this->stored( $post_id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $post_id Post id.
	 * @param array<string, mixed> $state   From snapshot().
	 */
	public function restore( int $post_id, array $state ): void {
		$this->write( $post_id, array_intersect_key( $state, array_flip( $this->fields() ) ) );
	}

	/**
	 * Target keywords (focus first) through SEOPress's REST route.
	 *
	 * @param int                  $post_id Post id.
	 * @param array<string, mixed> $patch   Flat patch.
	 * @throws ToolError When SEOPress refuses, or additional keywords come without a focus keyword.
	 */
	private function keywords( int $post_id, array $patch ): void {
		$current = $this->stored( $post_id );
		$focus   = array_key_exists( 'focus_keyword', $patch ) ? Seo::text( $patch['focus_keyword'] ) : $current['focus_keyword'];
		$extra   = array_key_exists( 'additional_keywords', $patch ) ? (array) $patch['additional_keywords'] : $current['additional_keywords'];
		$list    = array_values( array_unique( array_filter( array_map( array( Seo::class, 'text' ), array_merge( array( $focus ), $extra ) ) ) ) );
		if ( $list && null === $focus ) {
			throw new ToolError( 'SEOPress treats the first target keyword as the focus keyword: set focus_keyword when keeping additional keywords.' );
		}
		$request = new \WP_REST_Request( 'PUT', '/seopress/v1/posts/' . $post_id . '/target-keywords' );
		$request->set_param( self::KEYWORDS, implode( ',', $list ) );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( 'SEOPress refused the keywords: ' . wp_strip_all_tags( $response->as_error()->get_error_message() ) );
		}
	}

	/**
	 * SEOPress settings list ([ { key, value, … } ]) → key => value.
	 *
	 * @param array<mixed> $rows Rows.
	 * @return array<string, string>
	 */
	private static function values( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['key'] ) && is_scalar( $row['value'] ?? null ) ) {
				$out[ (string) $row['key'] ] = (string) $row['value'];
			}
		}
		return $out;
	}
}
