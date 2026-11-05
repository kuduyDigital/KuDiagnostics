<?php
/**
 * Rank Math SEO adapter.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Seo;

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Rank Math 1.0.281 (RESEARCH.md §9). Reads the post meta keys its editor uses (Screen::get_object_values());
 * writes through the endpoint its editor uses, `POST rankmath/v1/updateMeta` (its sanitizer per key; an empty value
 * deletes the key); rendered values through its ability `rank-math/get-post-seo-meta`. Rank Math has no ability that
 * writes post SEO data. Its role manager capabilities are required on top of edit_post.
 */
final class RankMathAdapter implements SeoAdapter {

	/**
	 * Text fields => meta key.
	 */
	private const TEXT = array(
		'title'               => 'rank_math_title',
		'description'         => 'rank_math_description',
		'canonical'           => 'rank_math_canonical_url',
		'og.title'            => 'rank_math_facebook_title',
		'og.description'      => 'rank_math_facebook_description',
		'twitter.title'       => 'rank_math_twitter_title',
		'twitter.description' => 'rank_math_twitter_description',
	);

	private const KEYWORDS      = 'rank_math_focus_keyword';
	private const PILLAR        = 'rank_math_pillar_content';
	private const ROBOTS        = 'rank_math_robots';
	private const USE_FACEBOOK  = 'rank_math_twitter_use_facebook';
	private const ROBOT_FLAGS   = array( 'nofollow', 'noarchive', 'nosnippet', 'noimageindex' );
	private const SNAPSHOT_KEYS = array( self::KEYWORDS, self::PILLAR, self::ROBOTS, self::USE_FACEBOOK );

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'rank-math';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Rank Math stops booting (no front-end output, no REST routes) until its account is connected or its setup
	 * wizard is skipped (`Helper::is_invalid_registration()`, includes/helpers/class-conditional.php).
	 */
	public function ready(): bool|string {
		if ( class_exists( '\RankMath\Helper' ) && \RankMath\Helper::is_invalid_registration() ) {
			return 'Rank Math is installed but not set up, so it outputs no SEO tags yet. In wp-admin open Rank Math and connect an account or skip the setup wizard, then try again.';
		}
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function fields(): array {
		return Seo::FIELDS;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post id.
	 */
	public function stored( int $post_id ): array {
		$out = array();
		foreach ( self::TEXT as $field => $key ) {
			$out[ $field ] = Seo::text( get_post_meta( $post_id, $key, true ) );
		}
		$keywords                   = self::keywords( $post_id );
		$out['focus_keyword']       = $keywords[0] ?? null;
		$out['additional_keywords'] = array_slice( $keywords, 1 );
		$out['cornerstone']         = 'on' === get_post_meta( $post_id, self::PILLAR, true );
		$robots                     = self::robots( $post_id );
		$out['robots.noindex']      = in_array( 'noindex', $robots, true ) ? true : ( in_array( 'index', $robots, true ) ? false : null );
		foreach ( self::ROBOT_FLAGS as $flag ) {
			$out[ 'robots.' . $flag ] = in_array( $flag, $robots, true );
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post id.
	 */
	public function rendered( int $post_id ): ?array {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'rank-math/get-post-seo-meta' ) : null;
		if ( ! $ability ) {
			return null;
		}
		$result = $ability->execute( array( 'post_id' => $post_id ) );
		if ( ! is_array( $result ) || isset( $result['error'] ) ) {
			return null;
		}
		return array(
			'title'               => (string) ( $result['title'] ?? '' ),
			'description'         => (string) ( $result['description'] ?? '' ),
			'canonical'           => (string) ( $result['canonical'] ?? '' ),
			'robots'              => array_values( array_map( 'strval', (array) ( $result['robots'] ?? array() ) ) ),
			'og.title'            => (string) ( $result['og_title'] ?? '' ),
			'og.description'      => (string) ( $result['og_description'] ?? '' ),
			'twitter.title'       => (string) ( $result['twitter_title'] ?? '' ),
			'twitter.description' => (string) ( $result['twitter_description'] ?? '' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $patch Flat patch.
	 */
	public function missing_caps( array $patch ): array {
		$need = array();
		foreach ( array_keys( $patch ) as $field ) {
			$need[] = match ( true ) {
				str_starts_with( $field, 'robots.' ), 'canonical' === $field => 'rank_math_onpage_advanced',
				str_starts_with( $field, 'og.' ), str_starts_with( $field, 'twitter.' ) => 'rank_math_onpage_social',
				default => 'rank_math_onpage_general',
			};
		}
		return array_values( array_filter( array_unique( $need ), static fn( $cap ) => ! current_user_can( $cap ) ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $post_id Post id.
	 * @param array<string, mixed> $patch   Flat patch.
	 * @throws ToolError When additional keywords are sent without a focus keyword, or Rank Math refuses.
	 */
	public function write( int $post_id, array $patch ): void {
		$meta = array();
		foreach ( self::TEXT as $field => $key ) {
			if ( array_key_exists( $field, $patch ) ) {
				$meta[ $key ] = (string) Seo::text( $patch[ $field ] );
			}
		}
		if ( array_key_exists( 'twitter.title', $patch ) || array_key_exists( 'twitter.description', $patch ) ) {
			// Rank Math copies the Facebook values to X unless "use Facebook data" is off.
			$meta[ self::USE_FACEBOOK ] = 'off';
		}
		if ( array_key_exists( 'focus_keyword', $patch ) || array_key_exists( 'additional_keywords', $patch ) ) {
			$current = self::keywords( $post_id );
			$focus   = array_key_exists( 'focus_keyword', $patch ) ? Seo::text( $patch['focus_keyword'] ) : ( $current[0] ?? null );
			$extra   = array_key_exists( 'additional_keywords', $patch ) ? (array) $patch['additional_keywords'] : array_slice( $current, 1 );
			$list    = array_values( array_filter( array_map( array( Seo::class, 'text' ), array_merge( array( $focus ), $extra ) ) ) );
			if ( $list && null === $focus ) {
				throw new ToolError( 'Rank Math treats the first keyword as the focus keyword: set focus_keyword when keeping additional keywords.' );
			}
			$meta[ self::KEYWORDS ] = implode( ',', array_unique( $list ) );
		}
		if ( array_key_exists( 'cornerstone', $patch ) ) {
			$meta[ self::PILLAR ] = $patch['cornerstone'] ? 'on' : '';
		}
		$robot_fields = array_filter( array_keys( $patch ), static fn( $f ) => str_starts_with( $f, 'robots.' ) );
		if ( $robot_fields ) {
			$robots = self::robots( $post_id );
			if ( array_key_exists( 'robots.noindex', $patch ) ) {
				$robots = array_diff( $robots, array( 'index', 'noindex' ) );
				if ( null !== $patch['robots.noindex'] ) {
					$robots[] = $patch['robots.noindex'] ? 'noindex' : 'index';
				}
			}
			foreach ( self::ROBOT_FLAGS as $flag ) {
				if ( array_key_exists( 'robots.' . $flag, $patch ) ) {
					$robots = array_diff( $robots, array( $flag ) );
					if ( $patch[ 'robots.' . $flag ] ) {
						$robots[] = $flag;
					}
				}
			}
			$meta[ self::ROBOTS ] = $robots ? array_values( array_unique( $robots ) ) : '';
		}
		$this->save( $post_id, $meta );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post id.
	 */
	public function snapshot( int $post_id ): array {
		$state = array();
		foreach ( array_merge( array_values( self::TEXT ), self::SNAPSHOT_KEYS ) as $key ) {
			$state[ $key ] = metadata_exists( 'post', $post_id, $key ) ? get_post_meta( $post_id, $key, true ) : '';
		}
		return $state;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $post_id Post id.
	 * @param array<string, mixed> $state   From snapshot().
	 */
	public function restore( int $post_id, array $state ): void {
		$allowed = array_merge( array_values( self::TEXT ), self::SNAPSHOT_KEYS );
		$this->save( $post_id, array_intersect_key( $state, array_flip( $allowed ) ) );
	}

	/**
	 * Send meta through Rank Math's own editor endpoint.
	 *
	 * @param int                  $post_id Post id.
	 * @param array<string, mixed> $meta    Meta key => value ('' deletes).
	 * @throws ToolError When Rank Math refuses.
	 */
	private function save( int $post_id, array $meta ): void {
		if ( ! $meta ) {
			return;
		}
		global $wp_filter;
		// The endpoint removes every is_protected_meta filter for the rest of the request; put them back afterwards.
		$protected = $wp_filter['is_protected_meta'] ?? null;
		$request   = new \WP_REST_Request( 'POST', '/rankmath/v1/updateMeta' );
		$request->set_param( 'objectType', 'post' );
		$request->set_param( 'objectID', $post_id );
		$request->set_param( 'meta', $meta );
		$response = rest_do_request( $request );
		if ( null === $protected ) {
			unset( $wp_filter['is_protected_meta'] );
		} else {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the hooks Rank Math removed.
			$wp_filter['is_protected_meta'] = $protected;
		}
		if ( $response->is_error() ) {
			$error = $response->as_error();
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( 'Rank Math refused the change: ' . wp_strip_all_tags( $error->get_error_message() ) );
		}
	}

	/**
	 * Stored keywords, focus first.
	 *
	 * @param int $post_id Post id.
	 * @return list<string>
	 */
	private static function keywords( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::KEYWORDS, true );
		return is_string( $raw ) ? array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ), static fn( string $k ): bool => '' !== $k ) ) : array();
	}

	/**
	 * Stored robots directives.
	 *
	 * @param int $post_id Post id.
	 * @return list<string>
	 */
	private static function robots( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::ROBOTS, true );
		return is_array( $raw ) ? array_values( array_map( 'strval', $raw ) ) : array();
	}
}
