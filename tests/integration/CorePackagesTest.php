<?php
/**
 * Phase 4: plugins and themes through core's real upgraders, offline (local ZIPs built with ZipArchive instead of wordpress.org).
 * Confirm policy, dependency impact, self-protection, undo (test matrix C7, P2).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class CorePackagesTest extends TestCase {

	use CallsTools;

	private const BASE  = 'kuduy-test-base/kuduy-test-base.php';
	private const NEEDS = 'kuduy-test-needs/kuduy-test-needs.php';
	private const DL    = 'https://downloads.wordpress.org/';

	private static int $admin;

	private static int $editor;

	/**
	 * Local ZIP per fake download URL.
	 *
	 * @var array<string, string>
	 */
	private static array $zips = array();

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		self::$admin  = self::make_user( 'administrator' );
		self::$editor = self::make_user( 'editor' );
		if ( ! class_exists( \ZipArchive::class ) ) {
			self::markTestSkipped( 'ZipArchive is not available to build the local test packages.' );
		}

		self::write_plugin( WP_PLUGIN_DIR . '/kuduy-test-base', 'Kuduy Test Base', '1.0' );
		self::write_plugin( WP_PLUGIN_DIR . '/kuduy-test-needs', 'Kuduy Test Needs', '1.0', 'Requires Plugins: kuduy-test-base' );
		self::write_theme( get_theme_root() . '/kuduy-test-theme', 'Kuduy Test Theme', '1.0' );
		wp_clean_plugins_cache();
		wp_clean_themes_cache();

		$build = get_temp_dir() . 'kuduy-zips';
		self::$zips[ self::DL . 'plugin/kuduy-test-fresh.zip' ]      = self::zip( $build, 'kuduy-test-fresh', static fn( $d ) => self::write_plugin( $d, 'Kuduy Test Fresh', '1.0' ) );
		self::$zips[ self::DL . 'plugin/kuduy-test-base.2.0.zip' ]   = self::zip( $build, 'kuduy-test-base', static fn( $d ) => self::write_plugin( $d, 'Kuduy Test Base', '2.0' ) );
		self::$zips[ self::DL . 'theme/kuduy-test-fresh-theme.zip' ] = self::zip( $build, 'kuduy-test-fresh-theme', static fn( $d ) => self::write_theme( $d, 'Kuduy Fresh Theme', '1.0' ) );
		self::$zips[ self::DL . 'theme/kuduy-test-theme.2.0.zip' ]   = self::zip( $build, 'kuduy-test-theme', static fn( $d ) => self::write_theme( $d, 'Kuduy Test Theme', '2.0' ) );

		add_filter( 'upgrader_pre_download', array( self::class, 'local_download' ), 5, 2 );
		add_filter( 'plugins_api', array( self::class, 'fake_api' ), 10, 3 );
		add_filter( 'themes_api', array( self::class, 'fake_api' ), 10, 3 );
	}

	public static function tearDownAfterClass(): void {
		remove_filter( 'upgrader_pre_download', array( self::class, 'local_download' ), 5 );
		remove_filter( 'plugins_api', array( self::class, 'fake_api' ), 10 );
		remove_filter( 'themes_api', array( self::class, 'fake_api' ), 10 );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	/**
	 * A minimal plugin (no functions, so including it twice is harmless).
	 *
	 * @param string $dir     Folder.
	 * @param string $name    Name.
	 * @param string $version Version.
	 * @param string $extra   Extra header line.
	 */
	private static function write_plugin( string $dir, string $name, string $version, string $extra = '' ): void {
		wp_mkdir_p( $dir );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		file_put_contents( $dir . '/' . basename( $dir ) . '.php', "<?php\n/**\n * Plugin Name: {$name}\n * Version: {$version}\n" . ( $extra ? " * {$extra}\n" : '' ) . " */\n" );
	}

	/**
	 * A minimal classic theme.
	 *
	 * @param string $dir     Folder.
	 * @param string $name    Name.
	 * @param string $version Version.
	 */
	private static function write_theme( string $dir, string $name, string $version ): void {
		wp_mkdir_p( $dir );
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		file_put_contents( $dir . '/style.css', "/*\nTheme Name: {$name}\nVersion: {$version}\n*/\n" );
		file_put_contents( $dir . '/index.php', "<?php\n" );
		// phpcs:enable
	}

	/**
	 * Build a ZIP whose top folder is $slug.
	 *
	 * @param string   $build Work folder.
	 * @param string   $slug  Top folder name.
	 * @param callable $fill  Writes the folder's files.
	 */
	private static function zip( string $build, string $slug, callable $fill ): string {
		$stage = $build . '/' . $slug . '-' . wp_generate_password( 6, false );
		$fill( $stage . '/' . $slug );
		$zip     = $stage . '.zip';
		$archive = new \ZipArchive();
		$archive->open( $zip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		foreach ( (array) glob( $stage . '/' . $slug . '/*' ) as $file ) {
			$archive->addFile( (string) $file, $slug . '/' . basename( (string) $file ) );
		}
		$archive->close();
		return $zip;
	}

	/**
	 * Serve fake download URLs from local ZIPs (a copy: the upgrader deletes what it unpacked).
	 *
	 * @param mixed $reply   Reply so far.
	 * @param mixed $package Package URL.
	 * @return mixed
	 */
	public static function local_download( $reply, $package ) {
		if ( false !== $reply || ! is_string( $package ) || ! isset( self::$zips[ $package ] ) ) {
			return $reply;
		}
		$copy = wp_tempnam( 'kuduy-test.zip' );
		copy( self::$zips[ $package ], $copy );
		return $copy;
	}

	/**
	 * Fake wordpress.org info: our test slugs exist, everything else does not.
	 *
	 * @param mixed  $result Result so far.
	 * @param string $action Action.
	 * @param mixed  $args   Arguments.
	 * @return mixed
	 */
	public static function fake_api( $result, $action, $args ) {
		$slug = is_object( $args ) ? (string) ( $args->slug ?? '' ) : '';
		$map  = array(
			'kuduy-test-fresh'       => self::DL . 'plugin/kuduy-test-fresh.zip',
			'kuduy-test-fresh-theme' => self::DL . 'theme/kuduy-test-fresh-theme.zip',
		);
		return isset( $map[ $slug ] )
			? (object) array(
				'slug'          => $slug,
				'download_link' => $map[ $slug ],
			)
			: new \WP_Error( 'not_found', 'Not found (test).' );
	}

	/**
	 * WP_Plugin_Dependencies caches plugin headers in static properties for the whole request; tests share one
	 * process, so reset them as a new request would (the test plugins were written after the cache was filled).
	 */
	private static function fresh_dependency_data(): void {
		$class = new \ReflectionClass( \WP_Plugin_Dependencies::class );
		foreach ( $class->getDefaultProperties() as $name => $default ) {
			$property = $class->getProperty( $name );
			if ( $property->isStatic() ) {
				$property->setValue( null, $default );
			}
		}
	}

	// ---------------------------------------------------------------- plugins.

	public function test_editors_cannot_manage_plugins_or_themes(): void {
		wp_set_current_user( self::$editor );
		foreach ( array( 'kuduy-plugin-list', 'kuduy-theme-list' ) as $tool ) {
			$this->fails( $tool, array(), $tool . ' needs admin rights (P2)' );
		}
		$this->fails( 'kuduy-plugin-install', array( 'slug' => 'kuduy-test-fresh' ), 'editors cannot install plugins (P2)' );
		$this->fails( 'kuduy-theme-switch', array( 'stylesheet' => 'kuduy-test-theme' ), 'editors cannot switch themes' );
	}

	public function test_list_shows_versions_and_dependencies(): void {
		$plugins = array_column( $this->ok( 'kuduy-plugin-list' )['plugins'], null, 'file' );

		$this->assertSame( '1.0', $plugins[ self::NEEDS ]['version'] );
		$this->assertSame( array( 'kuduy-test-base' ), $plugins[ self::NEEDS ]['requires_plugins'] );
		$this->assertTrue( $plugins['kuduy-mcp/kuduy-mcp.php']['active'] );
	}

	public function test_install_from_wordpress_org_slug_only(): void {
		$installed = $this->ok( 'kuduy-plugin-install', array( 'slug' => 'kuduy-test-fresh' ) );
		$this->assertSame( 'kuduy-test-fresh/kuduy-test-fresh.php', $installed['file'] );
		$this->assertFalse( $installed['active'] );

		$this->fails( 'kuduy-plugin-install', array( 'slug' => 'kuduy-test-fresh' ), 'already installed' );
		$this->fails( 'kuduy-plugin-install', array( 'slug' => 'https://evil.example/x.zip' ), 'URLs are not slugs' );
		$this->fails( 'kuduy-plugin-install', array( 'slug' => 'kuduy-unknown' ), 'unknown slug' );
	}

	public function test_activate_and_undo(): void {
		deactivate_plugins( self::BASE, true );
		$on = $this->ok( 'kuduy-plugin-activate', array( 'file' => self::BASE ) );
		$this->assertTrue( $on['active'] );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $on['_snapshot_id'] ) );
		$this->assertFalse( is_plugin_active( self::BASE ) );
	}

	public function test_deactivate_is_confirm_first_and_names_dependents(): void {
		activate_plugin( self::BASE );
		activate_plugin( self::NEEDS );

		self::fresh_dependency_data();
		$dry = $this->ok( 'kuduy-plugin-deactivate', array( 'file' => self::BASE ) );
		$this->assertFalse( $dry['done'] );
		$this->assertStringContainsString( 'Kuduy Test Needs', implode( ' ', $dry['impact'] ) );
		$this->assertTrue( is_plugin_active( self::BASE ), 'nothing changes without confirm' );

		$off = $this->ok(
			'kuduy-plugin-deactivate',
			array(
				'file'    => self::BASE,
				'confirm' => true,
			)
		);
		$this->assertTrue( $off['done'] );
		$this->assertFalse( is_plugin_active( self::BASE ) );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $off['_snapshot_id'] ) );
		$this->assertTrue( is_plugin_active( self::BASE ) );

		$this->fails(
			'kuduy-plugin-deactivate',
			array(
				'file'    => 'kuduy-mcp/kuduy-mcp.php',
				'confirm' => true,
			),
			'KUDUY never deactivates itself'
		);
		$this->assertTrue( is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) );
	}

	public function test_update_is_confirm_first_and_keeps_the_plugin_active(): void {
		activate_plugin( self::BASE );
		$offer               = new \stdClass();
		$offer->response     = array(
			self::BASE => (object) array(
				'slug'        => 'kuduy-test-base',
				'plugin'      => self::BASE,
				'new_version' => '2.0',
				'package'     => self::DL . 'plugin/kuduy-test-base.2.0.zip',
			),
		);
		$offer->last_checked = time();
		set_site_transient( 'update_plugins', $offer );

		$dry = $this->ok( 'kuduy-plugin-update', array( 'file' => self::BASE ) );
		$this->assertFalse( $dry['done'] );
		$this->assertStringContainsString( '1.0 to 2.0', $dry['impact'][0] );

		$done = $this->ok(
			'kuduy-plugin-update',
			array(
				'file'    => self::BASE,
				'confirm' => true,
			)
		);
		$this->assertTrue( $done['done'] );
		$this->assertSame( '2.0', $done['version'] );
		$this->assertTrue( is_plugin_active( self::BASE ), 'stays active, like background updates' );

		$this->fails(
			'kuduy-plugin-update',
			array(
				'file'    => 'kuduy-mcp/kuduy-mcp.php',
				'confirm' => true,
			),
			'KUDUY never updates itself through MCP'
		);
		$this->fails( 'kuduy-plugin-update', array( 'file' => self::NEEDS ), 'no update offered' );
	}

	// ----------------------------------------------------------------- themes.

	public function test_theme_switch_is_confirm_first_with_undo(): void {
		$before = get_stylesheet();

		$dry = $this->ok( 'kuduy-theme-switch', array( 'stylesheet' => 'kuduy-test-theme' ) );
		$this->assertFalse( $dry['done'] );
		$this->assertStringContainsString( 'Kuduy Test Theme', $dry['impact'][0] );
		$this->assertSame( $before, get_stylesheet() );

		$switched = $this->ok(
			'kuduy-theme-switch',
			array(
				'stylesheet' => 'kuduy-test-theme',
				'confirm'    => true,
			)
		);
		$this->assertTrue( $switched['active'] );
		$this->assertSame( 'kuduy-test-theme', get_stylesheet() );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $switched['_snapshot_id'] ) );
		$this->assertSame( $before, get_stylesheet() );
	}

	public function test_theme_install_list_and_update(): void {
		$installed = $this->ok( 'kuduy-theme-install', array( 'slug' => 'kuduy-test-fresh-theme' ) );
		$this->assertSame( 'Kuduy Fresh Theme', $installed['name'] );
		$this->assertFalse( $installed['active'] );
		$this->assertContains( 'kuduy-test-fresh-theme', array_column( $this->ok( 'kuduy-theme-list' )['themes'], 'stylesheet' ) );

		$offer               = new \stdClass();
		$offer->response     = array(
			'kuduy-test-theme' => array(
				'theme'       => 'kuduy-test-theme',
				'new_version' => '2.0',
				'package'     => self::DL . 'theme/kuduy-test-theme.2.0.zip',
			),
		);
		$offer->last_checked = time();
		set_site_transient( 'update_themes', $offer );

		$this->assertFalse( $this->ok( 'kuduy-theme-update', array( 'stylesheet' => 'kuduy-test-theme' ) )['done'] );
		$done = $this->ok(
			'kuduy-theme-update',
			array(
				'stylesheet' => 'kuduy-test-theme',
				'confirm'    => true,
			)
		);
		$this->assertSame( '2.0', $done['version'] );
	}
}
