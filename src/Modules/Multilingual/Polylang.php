<?php
/**
 * Polylang through its public API (decision D20 A).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Multilingual;

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Languages and translation groups of posts and terms with Polylang's documented pll_* functions (RESEARCH.md §10e).
 * Access follows the object: edit_post / edit_term to change a language or a translation group, read_post /
 * edit_term to read one. Managing the language list itself (adding languages) stays in Polylang's settings.
 */
final class Polylang {

	public const OBJECTS = array( 'post', 'term' );

	/**
	 * Availability.
	 */
	public static function available(): bool|string {
		return function_exists( 'pll_languages_list' ) && function_exists( 'pll_save_post_translations' ) ? true : 'Polylang is not active (WPML is not supported yet)';
	}

	/**
	 * Language slugs set up in Polylang.
	 *
	 * @return list<string>
	 */
	public static function languages(): array {
		return array_values( array_map( 'strval', (array) pll_languages_list( array( 'fields' => 'slug' ) ) ) );
	}

	/**
	 * Refuse an unknown language.
	 *
	 * @param string $lang Language slug.
	 * @throws ToolError When Polylang has no such language.
	 */
	public static function assert_language( string $lang ): void {
		if ( ! in_array( $lang, self::languages(), true ) ) {
			throw new ToolError( 'Polylang has no language "' . esc_html( $lang ) . '". Languages: ' . esc_html( implode( ', ', self::languages() ) ) . '.' );
		}
	}

	/**
	 * Whether the object exists and Polylang translates its type.
	 *
	 * @param string $kind post | term.
	 * @param int    $id   Id.
	 * @throws ToolError When it does not exist or is not translated.
	 */
	public static function assert_object( string $kind, int $id ): void {
		if ( 'post' === $kind ) {
			$post = get_post( $id );
			if ( ! $post ) {
				throw new ToolError( 'No post with id ' . (int) $id . '.' );
			}
			if ( ! pll_is_translated_post_type( $post->post_type ) ) {
				throw new ToolError( 'Polylang does not translate the post type "' . esc_html( $post->post_type ) . '" (see Languages → Settings).' );
			}
			return;
		}
		$term = get_term( $id );
		if ( ! $term instanceof \WP_Term ) {
			throw new ToolError( 'No term with id ' . (int) $id . '.' );
		}
		if ( ! pll_is_translated_taxonomy( $term->taxonomy ) ) {
			throw new ToolError( 'Polylang does not translate the taxonomy "' . esc_html( $term->taxonomy ) . '".' );
		}
	}

	/**
	 * Capability to read or change an object.
	 *
	 * @param string $kind  post | term.
	 * @param int    $id    Id.
	 * @param bool   $write Change.
	 * @return array{0: string, 1: int}
	 */
	public static function capability( string $kind, int $id, bool $write ): array {
		if ( 'term' === $kind ) {
			return array( 'edit_term', $id );
		}
		return array( $write ? 'edit_post' : 'read_post', $id );
	}

	/**
	 * Language slug of an object, or null.
	 *
	 * @param string $kind post | term.
	 * @param int    $id   Id.
	 */
	public static function language( string $kind, int $id ): ?string {
		$lang = 'post' === $kind ? pll_get_post_language( $id ) : pll_get_term_language( $id );
		return is_string( $lang ) && '' !== $lang ? $lang : null;
	}

	/**
	 * Translation group of an object: language => id (includes the object itself).
	 *
	 * @param string $kind post | term.
	 * @param int    $id   Id.
	 * @return array<string, int>
	 */
	public static function translations( string $kind, int $id ): array {
		$map = 'post' === $kind ? pll_get_post_translations( $id ) : pll_get_term_translations( $id );
		return array_map( 'intval', (array) $map );
	}

	/**
	 * Set an object's language.
	 *
	 * @param string $kind post | term.
	 * @param int    $id   Id.
	 * @param string $lang Language slug.
	 */
	public static function set_language( string $kind, int $id, string $lang ): void {
		if ( 'post' === $kind ) {
			pll_set_post_language( $id, $lang );
		} else {
			pll_set_term_language( $id, $lang );
		}
	}

	/**
	 * Take an object out of its translation group and remove its language (Polylang's own model methods), so it is
	 * back to "no language" (used by undo when the object had none).
	 *
	 * @param string $kind post | term.
	 * @param int    $id   Id.
	 */
	public static function clear_language( string $kind, int $id ): void {
		$model = 'post' === $kind ? PLL()->model->post : PLL()->model->term;
		$model->delete_translation( $id );
		$model->delete_language( $id );
	}

	/**
	 * Save a translation group.
	 *
	 * @param string             $kind post | term.
	 * @param array<string, int> $map  Language => id.
	 */
	public static function save_group( string $kind, array $map ): void {
		if ( 'post' === $kind ) {
			pll_save_post_translations( $map );
		} else {
			pll_save_term_translations( $map );
		}
	}

	/**
	 * One object with its language and translations, for results.
	 *
	 * @param string $kind post | term.
	 * @param int    $id   Id.
	 * @return array<string, mixed>
	 */
	public static function describe( string $kind, int $id ): array {
		$translations = array();
		foreach ( self::translations( $kind, $id ) as $lang => $tid ) {
			if ( 'post' === $kind ) {
				$readable = current_user_can( 'read_post', $tid );
				$title    = $readable ? get_the_title( $tid ) : '';
				$status   = $readable ? (string) get_post_status( $tid ) : '';
			} else {
				$term     = get_term( $tid );
				$readable = $term instanceof \WP_Term;
				$title    = $readable ? $term->name : '';
				$status   = '';
			}
			$translations[ $lang ] = array_filter(
				array(
					'id'     => $tid,
					'title'  => $readable ? $title : null,
					'status' => '' !== $status ? $status : null,
				),
				static fn( $v ) => null !== $v
			);
		}
		return array(
			'object'       => $kind,
			'id'           => $id,
			'language'     => self::language( $kind, $id ),
			'translations' => (object) $translations,
		);
	}
}
