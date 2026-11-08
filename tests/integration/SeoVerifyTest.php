<?php
/**
 * Phase 6 kuduy-seo-verify (test matrix S4): parses the live page's SEO tags and compares them with expected values.
 * Playground has no loopback HTTP, so the "live" response is supplied through pre_http_request. Each SEO plugin's
 * own test also runs kuduy-seo-verify on the head that plugin renders (RendersHead::verify_live()).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Modules\Seo\Seo;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class SeoVerifyTest extends TestCase {

	use CallsTools;

	private static int $admin;

	/**
	 * Body served for the next request.
	 *
	 * @var string
	 */
	private string $body = '';

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin = self::make_user( 'administrator' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
		add_filter( 'pre_http_request', array( $this, 'serve' ), 10, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'serve' ), 10 );
	}

	/**
	 * Answer requests to this site with $this->body.
	 *
	 * @param mixed                $pre  Short-circuit value.
	 * @param array<string, mixed> $args Request args.
	 * @param string               $url  URL.
	 * @return mixed
	 */
	public function serve( mixed $pre, array $args, string $url ): mixed {
		if ( ! str_starts_with( $url, home_url() ) ) {
			return $pre;
		}
		return array(
			'headers'  => array(),
			'body'     => $this->body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * A post.
	 *
	 * @param string $status Status.
	 */
	private static function post( string $status ): int {
		return (int) wp_insert_post(
			array(
				'post_title'  => 'Verify me',
				'post_status' => $status,
			)
		);
	}

	public function test_parses_tags_and_reports_mismatches(): void {
		$id         = self::post( 'publish' );
		$this->body = '<!doctype html><html><head><title>Shop &amp; more</title><meta name="description" content="Best shop."><meta name="robots" content="noindex, follow"><link rel="canonical" href="https://example.com/x/"><meta property="og:title" content="OG one"></head><body><title>not this</title></body></html>';
		$result     = $this->ok(
			'kuduy-seo-verify',
			array(
				'post_id' => $id,
				'expect'  => array(
					'title'   => 'Shop & more',
					'noindex' => true,
					'og'      => array( 'title' => 'OG two' ),
				),
			)
		);
		$this->assertTrue( $result['live_checked'] );
		$this->assertSame( 'Shop & more', $result['head']['title'] );
		$this->assertSame( 'Best shop.', $result['head']['description'] );
		$this->assertSame( 'https://example.com/x/', $result['head']['canonical'] );
		$this->assertSame( 'OG one', $result['head']['og']['title'] );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( 'og.title' ), array_column( $result['mismatches'], 'field' ) );

		$draft = $this->ok( 'kuduy-seo-verify', array( 'post_id' => self::post( 'draft' ) ) );
		$this->assertFalse( $draft['live_checked'] );
		$this->assertNull( $draft['head'] );
	}

	public function test_notes_when_search_engines_are_discouraged(): void {
		// WordPress core (wp_robots_noindex) adds noindex, nofollow to every page while blog_public is 0, whatever the
		// SEO plugin stores or renders, so both tools say so.
		$id         = self::post( 'publish' );
		$this->body = '<!doctype html><html><head><title>T</title><meta name="robots" content="noindex, nofollow"></head></html>';
		$previous   = get_option( 'blog_public' );
		update_option( 'blog_public', '0' );
		try {
			$verify = $this->ok( 'kuduy-seo-verify', array( 'post_id' => $id ) );
			$status = $this->ok( 'kuduy-seo-status' );
		} finally {
			update_option( 'blog_public', $previous );
		}
		$this->assertStringContainsString( 'Search engines are discouraged', implode( ' ', $verify['notes'] ) );
		$this->assertStringContainsString( 'Search engines are discouraged', implode( ' ', $status['notes'] ) );

		$public = $this->ok( 'kuduy-seo-status' );
		$this->assertStringNotContainsString( 'Search engines are discouraged', implode( ' ', $public['notes'] ) );
	}
}
