<?php
/**
 * Rank Math redirects, schema and sitemap status (test matrix S8–S10, decision D25 A): redirects through Rank Math's
 * Redirection class with undo and trash, loops refused; schema through its updateSchemas route with undo; sitemap
 * status from its ability. Runs only when Rank Math is active (KUDUY_PLUGINS=seo-by-rank-math@…); the sitemap test
 * also runs without it (WordPress's own sitemap).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class RankMathExtrasTest extends TestCase {

	use CallsTools;

	private static int $admin;
	private static int $author;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$author = self::make_user( 'author' );
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$modules = (array) get_option( 'rank_math_modules', array() );
			update_option( 'rank_math_modules', array_values( array_unique( array_merge( $modules, array( 'redirections', 'rich-snippet', 'sitemap' ) ) ) ) );
		}
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	/**
	 * Skip unless Rank Math is active.
	 */
	private function needs_rank_math(): void {
		if ( ! defined( 'RANK_MATH_VERSION' ) ) {
			$this->markTestSkipped( 'Rank Math is not active in this run.' );
		}
	}

	public function test_sitemap_status(): void {
		$status = $this->ok( 'kuduy-seo-sitemap' );
		$this->assertSame( defined( 'RANK_MATH_VERSION' ) ? 'rank-math' : ( defined( 'WPSEO_VERSION' ) ? 'yoast' : ( defined( 'AIOSEO_VERSION' ) ? 'aioseo' : ( defined( 'SEOPRESS_VERSION' ) ? 'seopress' : 'WordPress' ) ) ), $status['plugin'] );
	}

	public function test_redirects_save_undo_trash(): void {
		$this->needs_rank_math();
		$saved = $this->ok(
			'kuduy-seo-redirect-save',
			array(
				'sources' => array( array( 'pattern' => '/kuduy-old/' ) ),
				'url_to'  => home_url( '/kuduy-new/' ),
			)
		);
		$id    = (int) $saved['redirect']['id'];
		$this->assertSame( 301, $saved['redirect']['header_code'] );
		$this->assertSame( 'kuduy-old', trim( (string) $saved['redirect']['sources'][0]['pattern'], '/' ) );
		$this->assertContains( $id, array_column( $this->ok( 'kuduy-seo-redirects' )['redirects'], 'id' ) );

		$changed = $this->ok(
			'kuduy-seo-redirect-save',
			array(
				'id'          => $id,
				'header_code' => 302,
			)
		);
		$this->assertSame( 302, $changed['redirect']['header_code'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $changed['_snapshot_id'] ) );
		$this->assertSame( 301, (int) \RankMath\Redirections\DB::get_redirection_by_id( $id )['header_code'] );

		$trashed = $this->ok( 'kuduy-seo-redirect-trash', array( 'id' => $id ) );
		$this->assertSame( 'trashed', $trashed['redirect']['status'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $trashed['_snapshot_id'] ) );
		$this->assertSame( 'active', \RankMath\Redirections\DB::get_redirection_by_id( $id )['status'] );

		$this->fails(
			'kuduy-seo-redirect-save',
			array(
				'sources' => array( array( 'pattern' => '/kuduy-loop/' ) ),
				'url_to'  => home_url( '/kuduy-loop/' ),
			),
			'redirect to itself'
		);
		$this->fails( 'kuduy-seo-redirect-save', array( 'sources' => array( array( 'pattern' => '/x/' ) ) ), '301 without destination' );
		wp_set_current_user( self::$author );
		$this->fails( 'kuduy-seo-redirect-trash', array( 'id' => $id ), 'authors have no rank_math_redirections' );
	}

	public function test_schema_save_delete_undo(): void {
		$this->needs_rank_math();
		$post  = (int) wp_insert_post(
			array(
				'post_title'  => 'Kuduy schema',
				'post_status' => 'publish',
			)
		);
		$added = $this->ok(
			'kuduy-seo-schema-save',
			array(
				'post_id' => $post,
				'schema'  => array(
					'@type'    => 'Article',
					'headline' => 'Kuduy headline',
					'metadata' => array(
						'title'     => 'Article',
						'type'      => 'template',
						'isPrimary' => true,
					),
				),
			)
		);
		$this->assertCount( 1, $added['schemas'] );
		$this->assertSame( 'Article', $added['schemas'][0]['type'] );
		$schema_id = $added['schemas'][0]['schema_id'];

		$changed = $this->ok(
			'kuduy-seo-schema-save',
			array(
				'post_id'   => $post,
				'schema_id' => $schema_id,
				'schema'    => array(
					'@type'    => 'Article',
					'headline' => 'Changed headline',
				),
			)
		);
		$this->assertSame( 'Changed headline', $changed['schemas'][0]['schema']['headline'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $changed['_snapshot_id'] ) );
		$this->assertSame( 'Kuduy headline', $this->ok( 'kuduy-seo-schema-get', array( 'post_id' => $post ) )['schemas'][0]['schema']['headline'] );

		$deleted = $this->ok(
			'kuduy-seo-schema-delete',
			array(
				'post_id'   => $post,
				'schema_id' => $schema_id,
			)
		);
		$this->assertSame( array(), $deleted['schemas'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $deleted['_snapshot_id'] ) );
		$this->assertCount( 1, $this->ok( 'kuduy-seo-schema-get', array( 'post_id' => $post ) )['schemas'] );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $added['_snapshot_id'] ) );
		$this->assertSame( array(), $this->ok( 'kuduy-seo-schema-get', array( 'post_id' => $post ) )['schemas'], 'Undoing the add removes it.' );
		$this->fails(
			'kuduy-seo-schema-save',
			array(
				'post_id' => $post,
				'schema'  => array( 'headline' => 'no type' ),
			),
			'schema without @type'
		);
	}
}
