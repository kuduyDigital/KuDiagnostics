<?php
/**
 * Integration registry (test matrix IR1–IR3, decision D30): kuduy-integrations reports what is installed and active
 * on this site with the support class from the catalogue and the tools that are really available; the catalogue
 * itself is consistent (unique ids, known categories and classes, every tool prefix matches a registered tool, an
 * integration without tools is never called supported).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Mcp\ToolRegistry;
use Kuduy\Mcp\Modules\Core\IntegrationCatalog;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class IntegrationsTest extends TestCase {

	use CallsTools;

	private static int $admin;
	private static int $editor;
	private static int $subscriber;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin      = self::make_user( 'administrator' );
		self::$editor     = self::make_user( 'editor' );
		self::$subscriber = self::make_user( 'subscriber' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	public function test_catalogue_is_consistent(): void {
		$names = array_map( static fn( $tool ) => $tool->name(), ToolRegistry::create()->all() );
		$ids   = array();
		foreach ( IntegrationCatalog::all() as $row ) {
			$ids[] = $row['id'];
			$this->assertContains( $row['category'], IntegrationCatalog::CATEGORIES, $row['id'] );
			$this->assertContains( $row['support'], IntegrationCatalog::SUPPORT, $row['id'] );
			$this->assertContains( $row['kind'], array( 'plugin', 'theme', 'core' ), $row['id'] );
			$this->assertContains( $row['copy'], array( 'free', 'paid', 'core' ), $row['id'] );
			if ( 'core' !== $row['kind'] ) {
				$this->assertNotEmpty( $row['folders'], $row['id'] . ' needs a folder to be detected by.' );
			}
			foreach ( $row['prefixes'] as $prefix ) {
				$this->assertNotEmpty(
					array_filter( $names, static fn( string $name ) => str_starts_with( $name, $prefix ) ),
					$row['id'] . ': no registered tool starts with ' . $prefix
				);
			}
			if ( in_array( $row['support'], array( 'full', 'partial' ), true ) ) {
				$this->assertNotEmpty( $row['prefixes'], $row['id'] . ' is called supported but names no tools.' );
			} else {
				$this->assertSame( array(), $row['prefixes'], $row['id'] . ' names tools but is not called supported.' );
			}
			if ( 'full' !== $row['support'] && 'none' !== $row['support'] ) {
				$this->assertNotSame( '', $row['note'], $row['id'] . ' must say what is missing.' );
			}
		}//end foreach
		$this->assertSame( $ids, array_unique( $ids ), 'Ids are unique.' );
		$this->assertGreaterThanOrEqual( 58, count( $ids ), 'The whole target list is in the catalogue.' );
	}

	public function test_reports_what_is_on_this_site(): void {
		$result = $this->ok( 'kuduy-integrations' );
		$by     = array_column( $result['integrations'], null, 'id' );
		$this->assertArrayHasKey( 'gutenberg', $by, 'WordPress itself is always there.' );
		$this->assertSame( 'partial', $by['gutenberg']['support'] );
		$this->assertTrue( $by['gutenberg']['tools']['available'] );
		$this->assertSame( get_bloginfo( 'version' ), $by['gutenberg']['version'] );
		foreach ( $result['integrations'] as $item ) {
			$this->assertTrue( $item['installed'], 'Without all: true only installed integrations are listed: ' . $item['id'] );
		}
		$this->assertSame( count( $result['integrations'] ), $result['summary']['installed'] );
		$this->assertSame( $result['summary']['installed'], array_sum( $result['summary']['support'] ) );
		$this->assertSame( count( IntegrationCatalog::all() ), $result['summary']['catalogue'] );

		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$this->assertTrue( $by['elementor']['active'] );
			$this->assertSame( 'full', $by['elementor']['support'] );
			$this->assertSame( ELEMENTOR_VERSION, $by['elementor']['version'] );
			$this->assertTrue( $by['elementor']['tools']['available'] );
			$this->assertGreaterThanOrEqual( 14, $by['elementor']['tools']['count'] );
		}
		$this->assertArrayNotHasKey( 'bricks', $by );

		$all = array_column( $this->ok( 'kuduy-integrations', array( 'all' => true ) )['integrations'], null, 'id' );
		$this->assertCount( count( IntegrationCatalog::all() ), $all );
		$this->assertFalse( $all['bricks']['installed'] );
		$this->assertFalse( $all['bricks']['active'] );
		$this->assertSame( 'none', $all['bricks']['support'], 'A builder nobody has implemented is not dressed up.' );
		$this->assertArrayNotHasKey( 'tools', $all['bricks'] );
		$this->assertArrayNotHasKey( 'version', $all['bricks'] );
		$this->assertSame( 'partial', $all['wpbakery']['support'], 'Not verified against the plugin, so not full.' );
		$this->assertSame( defined( 'WPB_VC_VERSION' ), $all['wpbakery']['tools']['available'], 'Tools count as available only while their plugin is loaded.' );

		$forms = $this->ok(
			'kuduy-integrations',
			array(
				'all'      => true,
				'category' => 'forms',
			)
		)['integrations'];
		$this->assertSame( array( 'forms' ), array_values( array_unique( array_column( $forms, 'category' ) ) ) );
		$this->assertContains( 'ninja-forms', array_column( $forms, 'id' ) );
		$found = $this->ok(
			'kuduy-integrations',
			array(
				'all'    => true,
				'search' => 'rank',
			)
		)['integrations'];
		$this->assertSame( array( 'rank-math' ), array_column( $found, 'id' ) );
	}

	public function test_versions_only_for_those_who_manage_plugins(): void {
		wp_set_current_user( self::$editor );
		$by = array_column( $this->ok( 'kuduy-integrations' )['integrations'], null, 'id' );
		$this->assertArrayHasKey( 'gutenberg', $by );
		foreach ( $by as $item ) {
			$this->assertArrayNotHasKey( 'version', $item, 'An editor learns what is supported, not which versions run.' );
		}
		wp_set_current_user( self::$subscriber );
		$this->fails( 'kuduy-integrations', array(), 'a subscriber has no use for it' );
		wp_set_current_user( self::$admin );
		$this->fails( 'kuduy-integrations', array( 'category' => 'nonsense' ), 'unknown category' );
	}
}
