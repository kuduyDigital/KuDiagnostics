<?php
/**
 * SEO resolver: which SEO plugin is active, its adapter, and the normalized field set.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Seo;

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Exactly one SEO plugin may receive writes. With two or more active, each would print its own tags and Claude
 * could not know which one the site uses, so writes are refused (exit gate, test S1). Detection uses the version
 * constant each plugin's main file defines (RESEARCH.md §9).
 */
final class Seo {

	/**
	 * Known SEO plugins: slug => [ name, version constant, adapter class ].
	 */
	private const PLUGINS = array(
		'rank-math' => array( 'Rank Math SEO', 'RANK_MATH_VERSION', RankMathAdapter::class ),
		'yoast'     => array( 'Yoast SEO', 'WPSEO_VERSION', YoastAdapter::class ),
		'aioseo'    => array( 'All in One SEO', 'AIOSEO_VERSION', AioseoAdapter::class ),
		'seopress'  => array( 'SEOPress', 'SEOPRESS_VERSION', SeopressAdapter::class ),
	);

	/**
	 * Normalized field ids.
	 */
	public const FIELDS = array(
		'title',
		'description',
		'focus_keyword',
		'additional_keywords',
		'canonical',
		'cornerstone',
		'robots.noindex',
		'robots.nofollow',
		'robots.noarchive',
		'robots.nosnippet',
		'robots.noimageindex',
		'og.title',
		'og.description',
		'twitter.title',
		'twitter.description',
	);

	/**
	 * Active SEO plugins.
	 *
	 * @return array<string, array{name:string, version:string}>
	 */
	public static function detected(): array {
		$found = array();
		foreach ( self::PLUGINS as $slug => [ $name, $constant ] ) {
			if ( defined( $constant ) ) {
				$found[ $slug ] = array(
					'name'    => $name,
					'version' => (string) constant( $constant ),
				);
			}
		}
		return $found;
	}

	/**
	 * Display name of a plugin slug.
	 *
	 * @param string $slug Slug.
	 */
	public static function name( string $slug ): string {
		return self::PLUGINS[ $slug ][0] ?? $slug;
	}

	/**
	 * The adapter of the one active SEO plugin.
	 *
	 * @throws ToolError When none or several are active, or the plugin is not set up.
	 */
	public static function adapter(): SeoAdapter {
		$found = self::detected();
		if ( ! $found ) {
			throw new ToolError( 'No supported SEO plugin is active (Rank Math, Yoast SEO, All in One SEO or SEOPress).' );
		}
		if ( count( $found ) > 1 ) {
			$names = array_map( static fn( $p ) => $p['name'] . ' ' . $p['version'], $found );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( 'Several SEO plugins are active: ' . implode( ', ', $names ) . '. Each prints its own tags, so KuDiagnostics does not change SEO data until only one is active. Nothing was changed.' );
		}
		$slug    = (string) array_key_first( $found );
		$class   = self::PLUGINS[ $slug ][2];
		$adapter = new $class();
		$ready   = $adapter->ready();
		if ( true !== $ready ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( $ready );
		}
		return $adapter;
	}

	/**
	 * Warning while Settings → Reading discourages search engines: WordPress core (`wp_robots_noindex()`) then adds
	 * noindex, nofollow to every page on top of what the SEO plugin stores or renders.
	 */
	public static function visibility_note(): ?string {
		if ( get_option( 'blog_public' ) ) {
			return null;
		}
		return 'Search engines are discouraged (Settings → Reading), so WordPress adds noindex, nofollow to every page, whatever the SEO plugin stores or renders.';
	}

	/**
	 * True when at least one known SEO plugin is active, else why not.
	 */
	public static function available(): bool|string {
		return self::detected() ? true : 'No SEO plugin is active (Rank Math, Yoast SEO, All in One SEO or SEOPress).';
	}

	/**
	 * Nested tool input → flat patch (only keys that were sent).
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>
	 */
	public static function flatten( array $input ): array {
		$flat = array();
		foreach ( self::FIELDS as $field ) {
			$parts = explode( '.', $field );
			if ( 1 === count( $parts ) ) {
				if ( array_key_exists( $field, $input ) ) {
					$flat[ $field ] = $input[ $field ];
				}
			} elseif ( is_array( $input[ $parts[0] ] ?? null ) && array_key_exists( $parts[1], $input[ $parts[0] ] ) ) {
				$flat[ $field ] = $input[ $parts[0] ][ $parts[1] ];
			}
		}
		return $flat;
	}

	/**
	 * Flat values → nested output.
	 *
	 * @param array<string, mixed> $flat Flat values.
	 * @return array<string, mixed>
	 */
	public static function nest( array $flat ): array {
		$out = array();
		foreach ( $flat as $field => $value ) {
			$parts = explode( '.', (string) $field, 2 );
			if ( 2 === count( $parts ) ) {
				$out[ $parts[0] ][ $parts[1] ] = $value;
			} else {
				$out[ $field ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Run an SEO plugin's own WordPress Ability as the current user (core validates the input, checks the ability's
	 * permission and validates the output).
	 *
	 * @param string               $name  Ability name.
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 * @throws ToolError When the ability is missing or refuses.
	 */
	public static function ability( string $name, array $input ): array {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
		if ( ! $ability ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( 'The SEO plugin\'s ability ' . $name . ' is not registered on this site.' );
		}
		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( 'The SEO plugin refused: ' . wp_strip_all_tags( $result->get_error_message() ) );
		}
		return is_array( $result ) ? $result : array();
	}

	/**
	 * Text value: trimmed string, '' → null.
	 *
	 * @param mixed $value Value.
	 */
	public static function text( mixed $value ): ?string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		return '' === $value ? null : $value;
	}
}
