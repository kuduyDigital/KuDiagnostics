<?php
/**
 * All in One SEO adapter.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Seo;

/**
 * AIOSEO 5.0.5 (RESEARCH.md §9). Its data lives in its own `aioseo_posts` table, so KUDUY reads and writes only
 * through AIOSEO's abilities `aioseo-posts/seo-data-get` and `aioseo-posts/seo-data-update` (patch semantics,
 * permission `aioseo_page_general_settings` + edit_post, saved by Models\Post::savePost()). Rendered title and
 * description come from AIOSEO's meta classes. Robots: AIOSEO keeps "use the default" as its own flag, so
 * noindex null = default, false = index, true = noindex.
 */
final class AioseoAdapter implements SeoAdapter {

	private const FLAGS = array( 'nofollow', 'noarchive', 'nosnippet', 'noimageindex' );

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'aioseo';
	}

	/**
	 * {@inheritDoc}
	 */
	public function ready(): bool|string {
		return function_exists( 'wp_get_ability' ) && wp_get_ability( 'aioseo-posts/seo-data-update' )
			? true
			: 'All in One SEO is active but its abilities are not registered (needs WordPress 6.9+ and AIOSEO 4.9.8+).';
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
		$data    = Seo::ability( 'aioseo-posts/seo-data-get', array( 'postId' => $post_id ) );
		$robots  = (array) ( $data['robots'] ?? array() );
		$social  = (array) ( $data['social'] ?? array() );
		$default = ! empty( $robots['use_default'] );
		$out     = array(
			'title'               => Seo::text( $data['title'] ?? null ),
			'description'         => Seo::text( $data['description'] ?? null ),
			'focus_keyword'       => Seo::text( $data['focus_keyphrase'] ?? null ),
			'additional_keywords' => array_values( array_filter( array_map( 'strval', (array) ( $data['additional_keyphrases'] ?? array() ) ), static fn( string $k ): bool => '' !== $k ) ),
			'canonical'           => Seo::text( $data['canonical_url'] ?? null ),
			'cornerstone'         => ! empty( $data['pillar_content'] ),
			'robots.noindex'      => $default ? null : ! empty( $robots['noindex'] ),
			'og.title'            => Seo::text( $social['og_title'] ?? null ),
			'og.description'      => Seo::text( $social['og_description'] ?? null ),
			'twitter.title'       => Seo::text( $social['twitter_title'] ?? null ),
			'twitter.description' => Seo::text( $social['twitter_description'] ?? null ),
		);
		foreach ( self::FLAGS as $flag ) {
			$out[ 'robots.' . $flag ] = ! $default && ! empty( $robots[ $flag ] );
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post id.
	 */
	public function rendered( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post || ! function_exists( 'aioseo' ) ) {
			return null;
		}
		return array(
			'title'       => (string) aioseo()->meta->title->getPostTitle( $post ),
			'description' => (string) aioseo()->meta->description->getPostDescription( $post ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $patch Flat patch.
	 */
	public function missing_caps( array $patch ): array {
		return function_exists( 'aioseo' ) && aioseo()->access->hasAccess( 'aioseo_page_general_settings' ) ? array() : array( 'aioseo_page_general_settings' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $post_id Post id.
	 * @param array<string, mixed> $patch   Flat patch.
	 */
	public function write( int $post_id, array $patch ): void {
		$input = array( 'postId' => $post_id );
		$map   = array(
			'title'               => 'title',
			'description'         => 'description',
			'canonical'           => 'canonical_url',
			'focus_keyword'       => 'focus_keyphrase',
			'og.title'            => 'social.og_title',
			'og.description'      => 'social.og_description',
			'twitter.title'       => 'social.twitter_title',
			'twitter.description' => 'social.twitter_description',
		);
		foreach ( $map as $field => $target ) {
			if ( ! array_key_exists( $field, $patch ) ) {
				continue;
			}
			$value = Seo::text( $patch[ $field ] );
			if ( str_starts_with( $target, 'social.' ) ) {
				$input['social'][ substr( $target, 7 ) ] = $value;
			} else {
				$input[ $target ] = $value;
			}
		}
		if ( array_key_exists( 'additional_keywords', $patch ) ) {
			$input['additional_keyphrases'] = array_values( array_map( 'strval', (array) $patch['additional_keywords'] ) );
		}
		if ( array_key_exists( 'cornerstone', $patch ) ) {
			$input['pillar_content'] = (bool) $patch['cornerstone'];
		}
		if ( array_filter( array_keys( $patch ), static fn( $f ) => str_starts_with( $f, 'robots.' ) ) ) {
			$current = $this->stored( $post_id );
			$noindex = array_key_exists( 'robots.noindex', $patch ) ? $patch['robots.noindex'] : $current['robots.noindex'];
			$robots  = array( 'noindex' => (bool) $noindex );
			foreach ( self::FLAGS as $flag ) {
				$robots[ $flag ] = (bool) ( array_key_exists( 'robots.' . $flag, $patch ) ? $patch[ 'robots.' . $flag ] : $current[ 'robots.' . $flag ] );
			}
			$robots['use_default'] = null === $noindex && ! in_array( true, $robots, true );
			$input['robots']       = $robots;
		}
		Seo::ability( 'aioseo-posts/seo-data-update', $input );
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
		$this->write( $post_id, array_intersect_key( $state, array_flip( Seo::FIELDS ) ) );
	}
}
