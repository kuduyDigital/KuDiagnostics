<?php
/**
 * Phase 7 multilingual with Polylang (test matrix L1–L4, decision D20 A): languages and translated types, a post's
 * language and translations, setting a language with undo, linking translations with undo (languages must match,
 * every object editable), creating a translation draft. Runs only when Polylang is active
 * (KUDUY_PLUGINS=polylang@…); the test adds English and German through Polylang's own model.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class MultilingualTest extends TestCase {

	use CallsTools;

	private static int $editor;
	private static int $author;

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'POLYLANG_VERSION' ) ) {
			self::markTestSkipped( 'Polylang is not active in this run (KUDUY_PLUGINS).' );
		}
		if ( ! function_exists( 'pll_languages_list' ) ) {
			// Active but without its API (no context yet): a broken run, not a skip.
			self::fail( 'Polylang is active but its pll_* API is not loaded (the runner adds languages first).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$editor = self::make_user( 'editor' );
		self::$author = self::make_user( 'author' );
		foreach ( array( 'en_US', 'de_DE' ) as $locale ) {
			if ( ! in_array( substr( $locale, 0, 2 ), pll_languages_list(), true ) ) {
				PLL()->model->languages->add( array( 'locale' => $locale ) );
			}
		}
	}

	protected function setUp(): void {
		wp_set_current_user( self::$editor );
	}

	/**
	 * A post in a language.
	 *
	 * @param string $lang   Language slug.
	 * @param int    $author Author.
	 */
	private static function post( string $lang, int $author = 0 ): int {
		$id = (int) wp_insert_post(
			array(
				'post_title'  => 'Kuduy ' . $lang,
				'post_status' => 'publish',
				'post_author' => $author > 0 ? $author : self::$editor,
			)
		);
		pll_set_post_language( $id, $lang );
		return $id;
	}

	public function test_status_lists_languages(): void {
		$status = $this->ok( 'kuduy-lang-status' );
		$slugs  = array_column( $status['languages'], 'slug' );
		$this->assertContains( 'en', $slugs );
		$this->assertContains( 'de', $slugs );
		$this->assertSame( POLYLANG_VERSION, $status['version'] );
		$this->assertContains( 'post', $status['post_types'] );
	}

	public function test_set_language_with_undo(): void {
		$id  = self::post( 'en' );
		$set = $this->ok(
			'kuduy-lang-set',
			array(
				'id'       => $id,
				'language' => 'de',
			)
		);
		$this->assertSame( 'de', $set['language'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $set['_snapshot_id'] ) );
		$this->assertSame( 'en', pll_get_post_language( $id ) );
		$this->fails(
			'kuduy-lang-set',
			array(
				'id'       => $id,
				'language' => 'xx',
			),
			'unknown language'
		);

		// A post with no language: undo must bring back "no language" (found on staging 2026-11-10).
		$none = (int) wp_insert_post(
			array(
				'post_title'  => 'Kuduy no language',
				'post_status' => 'publish',
			)
		);
		PLL()->model->post->delete_language( $none );
		$this->assertFalse( pll_get_post_language( $none ) );
		$set = $this->ok(
			'kuduy-lang-set',
			array(
				'id'       => $none,
				'language' => 'en',
			)
		);
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $set['_snapshot_id'] ) );
		$this->assertFalse( pll_get_post_language( $none ), 'Undo removes the language again.' );
		$this->assertSame( array(), pll_get_post_translations( $none ) );
	}

	public function test_link_and_undo(): void {
		$en     = self::post( 'en' );
		$de     = self::post( 'de' );
		$linked = $this->ok( 'kuduy-lang-link', array( 'translations' => array( 'en' => $en, 'de' => $de ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- two languages.
		$this->assertSame( $de, (int) $linked['translations']['de']['id'] );
		$this->assertSame( $de, pll_get_post( $en, 'de' ) );
		$got = $this->ok( 'kuduy-lang-get', array( 'id' => $de ) );
		$this->assertSame( $en, (int) $got['translations']['en']['id'] );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $linked['_snapshot_id'] ) );
		$this->assertFalse( (bool) pll_get_post( $en, 'de' ), 'Undo unlinks them again.' );

		$this->fails( 'kuduy-lang-link', array( 'translations' => array( 'en' => $en, 'de' => $en ) ), 'one post for two languages' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- two languages.
		$this->fails( 'kuduy-lang-link', array( 'translations' => array( 'de' => $en, 'en' => $de ) ), 'languages do not match the posts' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- two languages.
		$others = self::post( 'de', self::$editor );
		$mine   = self::post( 'en', self::$author );
		wp_set_current_user( self::$author );
		$this->fails( 'kuduy-lang-link', array( 'translations' => array( 'en' => $mine, 'de' => $others ) ), 'author cannot edit the editor\'s post' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- two languages.
		$this->assertFalse( (bool) pll_get_post( $mine, 'de' ) );
	}

	public function test_translate_creates_a_linked_draft(): void {
		$en  = self::post( 'en' );
		$out = $this->ok(
			'kuduy-lang-translate',
			array(
				'id'       => $en,
				'language' => 'de',
				'title'    => 'Kuduy auf Deutsch',
				'content'  => 'Hallo Welt',
			)
		);
		$this->assertSame( 'de', $out['language'] );
		$this->assertSame( 'draft', get_post_status( $out['id'] ) );
		$this->assertSame( 'Kuduy auf Deutsch', get_the_title( $out['id'] ) );
		$this->assertSame( (int) $out['id'], pll_get_post( $en, 'de' ) );
		$this->fails(
			'kuduy-lang-translate',
			array(
				'id'       => $en,
				'language' => 'de',
				'title'    => 'Again',
			),
			'a German translation already exists'
		);
	}
}
