<?php
/**
 * GitHub self-update (architecture §11, test matrix updater rows).
 *
 * All HTTP is mocked with pre_http_request; core's real wp_update_plugins() is exercised.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use Kuduy\Mcp\Update\GitHubUpdater;
use PHPUnit\Framework\TestCase;

final class UpdaterTest extends TestCase {

	private const FILE = 'kuduy-mcp/kuduy-mcp.php';

	private const DL = 'https://github.com/kuduyDigital/KuDiagnostics/releases/download/';

	/**
	 * Requests seen: list of [url, args].
	 *
	 * @var list<array{0:string, 1:array<string, mixed>}>
	 */
	private array $seen = array();

	/**
	 * URL substring => [status, body, headers].
	 *
	 * @var array<string, array{0:int, 1:string, 2?:array<string, string>}>
	 */
	private array $routes = array();

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( self::FILE ) ) {
			activate_plugin( self::FILE );
		}
		Plugin::boot();
	}

	protected function setUp(): void {
		wp_set_current_user( 1 );
		delete_site_transient( 'update_plugins' );
		delete_site_transient( GitHubUpdater::CACHE );
		$this->seen   = array();
		$this->routes = array( 'api.wordpress.org/plugins/update-check' => array( 200, '{"plugins":[],"translations":[],"no_update":[]}' ) );
		add_filter( 'pre_http_request', array( $this, 'mock' ), 10, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock' ), 10 );
		remove_all_filters( 'kuduy_mcp_update_channel' );
	}

	/**
	 * HTTP mock.
	 *
	 * @param mixed                $pre  Short-circuit value.
	 * @param array<string, mixed> $args Request args.
	 * @param string               $url  URL.
	 * @return mixed
	 */
	public function mock( $pre, array $args, string $url ) {
		$this->seen[] = array( $url, $args );
		foreach ( $this->routes as $needle => $route ) {
			if ( str_contains( $url, $needle ) ) {
				return array(
					'headers'  => $route[2] ?? array(),
					'body'     => $route[1],
					'response' => array(
						'code'    => $route[0],
						'message' => 'mock',
					),
					'cookies'  => array(),
				);
			}
		}
		return new \WP_Error( 'http_request_failed', 'unmocked: ' . $url );
	}

	/**
	 * A release JSON.
	 *
	 * @param string $tag      Tag.
	 * @param bool   $checksum Include the .sha256 asset.
	 * @param bool   $pre      Pre-release.
	 * @return array<string, mixed>
	 */
	private static function release( string $tag, bool $checksum = true, bool $pre = false ): array {
		$assets = array(
			array(
				'name'                 => 'kuduy-mcp.zip',
				'browser_download_url' => self::DL . $tag . '/kuduy-mcp.zip',
			),
		);
		if ( $checksum ) {
			$assets[] = array(
				'name'                 => 'kuduy-mcp.zip.sha256',
				'browser_download_url' => self::DL . $tag . '/kuduy-mcp.zip.sha256',
			);
		}
		return array(
			'tag_name'     => $tag,
			'name'         => $tag,
			'body'         => "Fixes <script>alert(1)</script> and adds things.\n- item",
			'html_url'     => 'https://github.com/kuduyDigital/KuDiagnostics/releases/tag/' . $tag,
			'draft'        => false,
			'prerelease'   => $pre,
			'published_at' => '2026-11-05T10:00:00Z',
			'assets'       => $assets,
		);
	}

	/**
	 * Serve one release as /releases/latest.
	 *
	 * @param array<string, mixed> $release Release.
	 */
	private function latest_is( array $release ): void {
		$this->routes = array_merge(
			array(
				'api.github.com/repos/kuduyDigital/KuDiagnostics/releases/latest' => array(
					200,
					(string) wp_json_encode( $release ),
					array( 'etag' => '"abc"' ),
				),
			),
			$this->routes
		);
	}

	private function offered(): ?object {
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		$t = get_site_transient( 'update_plugins' );
		return is_object( $t ) ? ( $t->response[ self::FILE ] ?? null ) : null;
	}

	public function test_header_version_matches_constant_and_update_uri(): void {
		$data = get_plugin_data( WP_PLUGIN_DIR . '/' . self::FILE, false, false );

		$this->assertSame( Plugin::VERSION, $data['Version'] );
		$this->assertSame( 'https://github.com/kuduyDigital/KuDiagnostics', $data['UpdateURI'] );
		$this->assertSame( self::FILE, GitHubUpdater::basename() );
	}

	public function test_newer_release_is_offered_through_core(): void {
		$this->latest_is( self::release( 'v9.9.9' ) );

		$update = $this->offered();

		$this->assertNotNull( $update );
		$this->assertSame( '9.9.9', $update->new_version );
		$this->assertSame( self::DL . 'v9.9.9/kuduy-mcp.zip', $update->package );
		$this->assertSame( 'kuduy-mcp', $update->slug );
		$github = array_values( array_filter( $this->seen, static fn( $r ) => str_contains( $r[0], 'api.github.com' ) ) );
		$this->assertStringStartsWith( 'KUDUY-MCP/', $github[0][1]['headers']['User-Agent'] ?? '' );
	}

	public function test_current_version_or_missing_checksum_is_not_offered(): void {
		$this->latest_is( self::release( 'v' . Plugin::VERSION ) );
		$this->assertNull( $this->offered(), 'same version' );

		delete_site_transient( GitHubUpdater::CACHE );
		$this->routes = array( 'api.wordpress.org/plugins/update-check' => array( 200, '{"plugins":[],"translations":[],"no_update":[]}' ) );
		$this->latest_is( self::release( 'v9.9.9', false ) );
		$this->assertNull( $this->offered(), 'release without .sha256 asset' );
	}

	public function test_other_github_plugins_are_untouched(): void {
		$this->latest_is( self::release( 'v9.9.9' ) );

		$this->assertFalse( GitHubUpdater::filter_update( false, array(), 'other/other.php' ) );
		$this->assertSame( array(), array_filter( $this->seen, static fn( $r ) => str_contains( $r[0], 'api.github.com' ) ) );
	}

	public function test_offered_even_when_wordpress_org_is_unreachable(): void {
		$this->latest_is( self::release( 'v9.9.9' ) );
		$this->routes['api.wordpress.org/plugins/update-check'] = array( 500, 'down' );
		GitHubUpdater::latest( true );

		$this->assertSame( '9.9.9', $this->offered()?->new_version );
	}

	public function test_release_check_is_cached_and_conditional(): void {
		$this->latest_is( self::release( 'v9.9.9' ) );

		GitHubUpdater::latest();
		GitHubUpdater::latest();
		$calls = array_values( array_filter( $this->seen, static fn( $r ) => str_contains( $r[0], 'api.github.com' ) ) );
		$this->assertCount( 1, $calls, 'second check served from cache' );

		$this->routes = array( 'api.github.com/repos/kuduyDigital/KuDiagnostics/releases/latest' => array( 304, '' ) );
		$this->assertSame( '9.9.9', GitHubUpdater::latest( true )['version'] ?? null, '304 keeps the cached release' );
		$last = end( $this->seen );
		$this->assertSame( '"abc"', $last[1]['headers']['If-None-Match'] ?? null );
	}

	public function test_check_again_on_updates_screen_skips_the_cache(): void {
		$this->latest_is( self::release( 'v9.9.9' ) );
		$this->assertSame( '9.9.9', $this->offered()?->new_version );

		$this->routes = array_merge( array( 'releases/latest' => array( 200, (string) wp_json_encode( self::release( 'v9.9.10' ) ) ) ), $this->routes );
		$this->assertSame( '9.9.9', $this->offered()?->new_version, 'normal checks use the cache' );

		$on_updates_screen = static function () {
			delete_site_transient( 'update_plugins' );
			wp_update_plugins();
		};
		remove_all_actions( 'load-update-core.php' );
		add_action( 'load-update-core.php', $on_updates_screen );

		do_action( 'load-update-core.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- replays the core hook.
		$this->assertSame( '9.9.9', get_site_transient( 'update_plugins' )->response[ self::FILE ]->new_version ?? null, 'opening the screen alone keeps the cache' );

		$_GET['force-check'] = '1';
		do_action( 'load-update-core.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- replays the core hook.
		unset( $_GET['force-check'] );
		$this->assertSame( '9.9.10', get_site_transient( 'update_plugins' )->response[ self::FILE ]->new_version ?? null, '"Check again" asks GitHub' );

		remove_action( 'load-update-core.php', $on_updates_screen );
		add_action( 'load-update-core.php', 'wp_update_plugins' );
	}

	public function test_beta_channel_takes_prereleases(): void {
		add_filter( 'kuduy_mcp_update_channel', static fn() => 'beta' );
		$this->routes = array(
			'api.github.com/repos/kuduyDigital/KuDiagnostics/releases?' => array(
				200,
				(string) wp_json_encode( array( self::release( 'v9.10.0-beta.1', true, true ), self::release( 'v9.9.9' ) ) ),
			),
		);

		$this->assertSame( '9.10.0-beta.1', GitHubUpdater::latest( true )['version'] ?? null );
	}

	public function test_checksum_is_verified_before_install(): void {
		$zip = 'PK-fake-zip-bytes';
		$this->latest_is( self::release( 'v9.9.9' ) );
		$this->routes['v9.9.9/kuduy-mcp.zip.sha256'] = array( 200, hash( 'sha256', $zip ) . "  kuduy-mcp.zip\n" );
		$this->routes['v9.9.9/kuduy-mcp.zip']        = array( 200, $zip );
		// The .sha256 route must win over the .zip prefix match.
		uksort( $this->routes, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );

		$file = GitHubUpdater::pre_download( false, self::DL . 'v9.9.9/kuduy-mcp.zip' );
		$this->assertIsString( $file );
		$this->assertSame( $zip, file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $file );

		$this->routes['v9.9.9/kuduy-mcp.zip.sha256'] = array( 200, str_repeat( '0', 64 ) . "  kuduy-mcp.zip\n" );
		$bad = GitHubUpdater::pre_download( false, self::DL . 'v9.9.9/kuduy-mcp.zip' );
		$this->assertInstanceOf( \WP_Error::class, $bad );
		$this->assertSame( 'kuduy_mcp_checksum', $bad->get_error_code() );

		$this->assertFalse( GitHubUpdater::pre_download( false, 'https://downloads.wordpress.org/plugin/other.zip' ), 'other packages untouched' );
	}

	public function test_details_modal_escapes_release_notes(): void {
		$this->latest_is( self::release( 'v9.9.9' ) );

		$info = GitHubUpdater::plugins_api( false, 'plugin_information', (object) array( 'slug' => 'kuduy-mcp' ) );

		$this->assertIsObject( $info );
		$this->assertSame( '9.9.9', $info->version );
		$this->assertSame( self::DL . 'v9.9.9/kuduy-mcp.zip', $info->download_link );
		$this->assertStringNotContainsString( '<script>', $info->sections['changelog'] );
		$this->assertStringContainsString( 'adds things', $info->sections['changelog'] );
		$this->assertFalse( GitHubUpdater::plugins_api( false, 'plugin_information', (object) array( 'slug' => 'other' ) ) );
	}

	public function test_check_update_tool(): void {
		$this->latest_is( self::release( 'v9.9.9' ) );
		$call = static function (): array {
			$request = new \WP_REST_Request( 'POST', '/kuduy-mcp/v1/mcp' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_header( 'MCP-Protocol-Version', '2026-07-28' );
			$request->set_header( 'Mcp-Method', 'tools/call' );
			$request->set_header( 'Mcp-Name', 'kuduy-check-update' );
			$request->set_body(
				(string) wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'id'      => 1,
						'method'  => 'tools/call',
						'params'  => array(
							'name'  => 'kuduy-check-update',
							'_meta' => array(
								'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
								'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
							),
						),
					)
				)
			);
			return rest_get_server()->dispatch( $request )->get_data()['result'];
		};

		$result = $call();
		$this->assertFalse( $result['isError'], (string) wp_json_encode( $result ) );
		$this->assertTrue( $result['structuredContent']['update_available'] );
		$this->assertSame( '9.9.9', $result['structuredContent']['latest_version'] );
		$this->assertSame( Plugin::VERSION, $result['structuredContent']['installed_version'] );

		$sub = wp_insert_user(
			array(
				'user_login' => 'upd_sub_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'subscriber',
			)
		);
		wp_set_current_user( is_wp_error( $sub ) ? 0 : $sub );
		$this->assertTrue( $call()['isError'] );
	}
}
