<?php
/**
 * Yoast SEO adapter.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Seo;

/**
 * Yoast SEO 28.8 (RESEARCH.md §9). Stored values through `WPSEO_Meta::get_value()` / `set_value()` / `delete()`
 * (Yoast's sanitize callbacks run through register_meta); rendered values through the Surfaces API
 * `YoastSEO()->meta->for_post()`. Yoast's own post SEO abilities are not used: they exist only on production-mode
 * sites and cannot set the focus keyphrase. Yoast rebuilds its indexable (what it prints) from the meta at shutdown;
 * KuDiagnostics asks its post watcher to do that right away so the result shows the new values.
 */
final class YoastAdapter implements SeoAdapter {

	/**
	 * Text fields => WPSEO_Meta key.
	 */
	private const TEXT = array(
		'title'               => 'title',
		'description'         => 'metadesc',
		'focus_keyword'       => 'focuskw',
		'canonical'           => 'canonical',
		'og.title'            => 'opengraph-title',
		'og.description'      => 'opengraph-description',
		'twitter.title'       => 'twitter-title',
		'twitter.description' => 'twitter-description',
	);

	private const ADVANCED_FLAGS = array( 'noarchive', 'nosnippet', 'noimageindex' );

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'yoast';
	}

	/**
	 * {@inheritDoc}
	 */
	public function ready(): bool|string {
		return class_exists( '\WPSEO_Meta' ) && function_exists( 'YoastSEO' ) ? true : 'Yoast SEO is active but not loaded yet.';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Social fields only when Yoast's Open Graph / X card features are on (WPSEO_Meta registers them only then).
	 */
	public function fields(): array {
		$fields = array( 'title', 'description', 'focus_keyword', 'canonical', 'cornerstone', 'robots.noindex', 'robots.nofollow', 'robots.noarchive', 'robots.nosnippet', 'robots.noimageindex' );
		if ( true === \WPSEO_Options::get( 'opengraph' ) ) {
			array_push( $fields, 'og.title', 'og.description' );
		}
		if ( true === \WPSEO_Options::get( 'twitter' ) ) {
			array_push( $fields, 'twitter.title', 'twitter.description' );
		}
		return $fields;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post id.
	 */
	public function stored( int $post_id ): array {
		$fields = $this->fields();
		$out    = array();
		foreach ( self::TEXT as $field => $key ) {
			if ( in_array( $field, $fields, true ) ) {
				$out[ $field ] = Seo::text( \WPSEO_Meta::get_value( $key, $post_id ) );
			}
		}
		$out['cornerstone']     = '1' === \WPSEO_Meta::get_value( 'is_cornerstone', $post_id );
		$noindex                = (string) \WPSEO_Meta::get_value( 'meta-robots-noindex', $post_id );
		$out['robots.noindex']  = '1' === $noindex ? true : ( '2' === $noindex ? false : null );
		$out['robots.nofollow'] = '1' === \WPSEO_Meta::get_value( 'meta-robots-nofollow', $post_id );
		$advanced               = self::advanced( $post_id );
		foreach ( self::ADVANCED_FLAGS as $flag ) {
			$out[ 'robots.' . $flag ] = in_array( $flag, $advanced, true );
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post id.
	 */
	public function rendered( int $post_id ): ?array {
		// The context memoizer keeps each page's values for the request; forget them so a write is visible.
		$memoizer = YoastSEO()->classes->get( \Yoast\WP\SEO\Memoizers\Meta_Tags_Context_Memoizer::class );
		if ( is_object( $memoizer ) && method_exists( $memoizer, 'clear' ) ) {
			$memoizer->clear();
		}
		$meta = YoastSEO()->meta->for_post( $post_id );
		if ( ! $meta ) {
			return null;
		}
		// Yoast's presentation keeps robots as [ 'index' => 'noindex', 'follow' => 'nofollow', … ].
		$robots = array_values( array_filter( array_map( 'strval', (array) $meta->robots ), static fn( string $v ): bool => '' !== $v ) );
		return array(
			'title'               => (string) $meta->title,
			'description'         => (string) $meta->description,
			'canonical'           => (string) $meta->canonical,
			'robots'              => $robots,
			'og.title'            => (string) $meta->open_graph_title,
			'og.description'      => (string) $meta->open_graph_description,
			'twitter.title'       => (string) $meta->twitter_title,
			'twitter.description' => (string) $meta->twitter_description,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Robots and canonical sit in Yoast's "advanced" tab: `wpseo_edit_advanced_metadata`, unless the site lets every
	 * author edit them (option disableadvanced_meta off), as in Yoast's metabox.
	 *
	 * @param array<string, mixed> $patch Flat patch.
	 */
	public function missing_caps( array $patch ): array {
		$advanced = array_filter( array_keys( $patch ), static fn( $f ) => 'canonical' === $f || str_starts_with( $f, 'robots.' ) );
		if ( ! $advanced || \WPSEO_Capability_Utils::current_user_can( 'wpseo_edit_advanced_metadata' ) || false === \WPSEO_Options::get( 'disableadvanced_meta' ) ) {
			return array();
		}
		return array( 'wpseo_edit_advanced_metadata' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $post_id Post id.
	 * @param array<string, mixed> $patch   Flat patch.
	 */
	public function write( int $post_id, array $patch ): void {
		foreach ( self::TEXT as $field => $key ) {
			if ( array_key_exists( $field, $patch ) ) {
				self::put( $post_id, $key, Seo::text( $patch[ $field ] ) );
			}
		}
		if ( array_key_exists( 'cornerstone', $patch ) ) {
			self::put( $post_id, 'is_cornerstone', $patch['cornerstone'] ? '1' : null );
		}
		if ( array_key_exists( 'robots.noindex', $patch ) ) {
			$noindex = $patch['robots.noindex'];
			self::put( $post_id, 'meta-robots-noindex', null === $noindex ? null : ( $noindex ? '1' : '2' ) );
		}
		if ( array_key_exists( 'robots.nofollow', $patch ) ) {
			self::put( $post_id, 'meta-robots-nofollow', $patch['robots.nofollow'] ? '1' : null );
		}
		$advanced = self::advanced( $post_id );
		$changed  = false;
		foreach ( self::ADVANCED_FLAGS as $flag ) {
			if ( array_key_exists( 'robots.' . $flag, $patch ) ) {
				$advanced = array_values( array_diff( $advanced, array( $flag ) ) );
				if ( $patch[ 'robots.' . $flag ] ) {
					$advanced[] = $flag;
				}
				$changed = true;
			}
		}
		if ( $changed ) {
			self::put( $post_id, 'meta-robots-adv', $advanced ? implode( ',', $advanced ) : null );
		}
		$this->rebuild( $post_id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post id.
	 */
	public function snapshot( int $post_id ): array {
		$state = array();
		foreach ( self::keys() as $key ) {
			$state[ $key ] = metadata_exists( 'post', $post_id, \WPSEO_Meta::$meta_prefix . $key ) ? (string) \WPSEO_Meta::get_value( $key, $post_id ) : null;
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
		foreach ( self::keys() as $key ) {
			if ( array_key_exists( $key, $state ) ) {
				self::put( $post_id, $key, null === $state[ $key ] ? null : (string) $state[ $key ] );
			}
		}
		$this->rebuild( $post_id );
	}

	/**
	 * Every WPSEO_Meta key write() can change.
	 *
	 * @return list<string>
	 */
	private static function keys(): array {
		return array_merge( array_values( self::TEXT ), array( 'is_cornerstone', 'meta-robots-noindex', 'meta-robots-nofollow', 'meta-robots-adv' ) );
	}

	/**
	 * Set or (null) delete one Yoast field.
	 *
	 * @param int         $post_id Post id.
	 * @param string      $key     WPSEO_Meta key.
	 * @param string|null $value   Value.
	 */
	private static function put( int $post_id, string $key, ?string $value ): void {
		if ( null === $value ) {
			\WPSEO_Meta::delete( $key, $post_id );
		} else {
			\WPSEO_Meta::set_value( $key, $value, $post_id );
		}
	}

	/**
	 * Advanced robots flags.
	 *
	 * @param int $post_id Post id.
	 * @return list<string>
	 */
	private static function advanced( int $post_id ): array {
		$raw = (string) \WPSEO_Meta::get_value( 'meta-robots-adv', $post_id );
		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ), static fn( string $f ): bool => in_array( $f, self::ADVANCED_FLAGS, true ) ) );
	}

	/**
	 * Rebuild Yoast's indexable now (its post meta watcher would do it at shutdown).
	 *
	 * @param int $post_id Post id.
	 */
	private function rebuild( int $post_id ): void {
		$watcher = YoastSEO()->classes->get( \Yoast\WP\SEO\Integrations\Watchers\Indexable_Post_Watcher::class );
		if ( is_object( $watcher ) && method_exists( $watcher, 'build_indexable' ) ) {
			$watcher->build_indexable( $post_id );
		}
	}
}
