<?php
/**
 * Phase 5 add-ons (test matrix EL9): widgets of Essential Addons, Ultimate Addons (header-footer-elementor),
 * Premium Addons and (upgrade plan phase F) seven more free add-ons through the generic Elementor tools — discovery, schema, create, edit, render.
 * Runs when the add-ons are installed (KUDUY_PLUGINS); skips otherwise.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class ElementorAddonsTest extends TestCase {

	use CallsTools;

	private static int $admin;

	public static function setUpBeforeClass(): void {
		if ( ! did_action( 'elementor/loaded' ) ) {
			self::markTestSkipped( 'Elementor is not active in this run.' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin = self::make_user( 'administrator' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	/**
	 * Add-on plugin folders.
	 *
	 * @return array<string, array{0:string}>
	 */
	public static function addons(): array {
		return array(
			'Essential Addons' => array( 'essential-addons-for-elementor-lite' ),
			'Ultimate Addons'  => array( 'header-footer-elementor' ),
			'Premium Addons'   => array( 'premium-addons-for-elementor' ),
			'Happy Addons'     => array( 'happy-elementor-addons' ),
			'HT Mega'          => array( 'ht-mega-for-elementor' ),
			'PowerPack'        => array( 'powerpack-lite-for-elementor' ),
			'Qi Addons'        => array( 'qi-addons-for-elementor' ),
			'Xpro'             => array( 'xpro-elementor-addons' ),
			'Element Pack'     => array( 'bdthemes-element-pack-lite' ),
			'Piotnet'          => array( 'piotnet-addons-for-elementor' ),
		);
	}

	/**
	 * Widgets the add-on registers, as KUDUY lists them.
	 *
	 * @param string $folder Plugin folder.
	 * @return list<array<string, mixed>>
	 */
	private function widgets_of( string $folder ): array {
		$widgets = $this->ok( 'kuduy-elementor-widgets' )['widgets'];
		return array_values( array_filter( $widgets, static fn( $w ) => $folder === $w['source'] ) );
	}

	/**
	 * Discovery, schema, create, edit and render with one widget of the add-on.
	 *
	 * @dataProvider addons
	 *
	 * @param string $folder Plugin folder.
	 */
	public function test_addon_widgets_work_through_the_generic_tools( string $folder ): void {
		if ( ! is_dir( WP_PLUGIN_DIR . '/' . $folder ) ) {
			$this->markTestSkipped( $folder . ' is not installed in this run (KUDUY_PLUGINS).' );
		}
		$widgets = $this->widgets_of( $folder );
		$this->assertNotEmpty( $widgets, $folder . ' registers widgets that KUDUY lists with their source' );
		$this->assertNotSame( $folder, $widgets[0]['plugin'], 'the add-on is named by its plugin header, not only its folder' );

		// The first widget whose schema has a plain text control (so an edit can be checked).
		$widget  = null;
		$control = null;
		foreach ( $widgets as $candidate ) {
			$schema = $this->ok( 'kuduy-elementor-widget-schema', array( 'type' => $candidate['name'] ) );
			foreach ( $schema['controls'] as $row ) {
				if ( 'text' === $row['type'] && empty( $row['device_keys'] ) ) {
					$widget  = $candidate;
					$control = $row['name'];
					break 2;
				}
			}
		}
		$this->assertNotNull( $widget, $folder . ': no widget with a text control found among ' . count( $widgets ) . ' widgets' );

		$page = $this->ok(
			'kuduy-elementor-create',
			array(
				'title'    => 'Kuduy add-on test ' . $folder,
				'elements' => array(
					array(
						'elType'   => 'container',
						'elements' => array(
							array(
								'elType'     => 'widget',
								'widgetType' => $widget['name'],
							),
						),
					),
				),
			)
		);
		$this->assertSame( 2, $page['element_count'], 'Elementor kept the add-on widget on save' );
		$tree = $this->ok( 'kuduy-elementor-get', array( 'id' => $page['id'] ) );
		$this->assertSame( array(), $tree['unregistered'] );
		$element_id = (string) $tree['elements'][0]['elements'][0]['id'];

		$this->ok(
			'kuduy-elementor-edit',
			array(
				'id'         => $page['id'],
				'operations' => array(
					array(
						'action'     => 'update',
						'element_id' => $element_id,
						'settings'   => array( $control => 'KUDUY add-on edit' ),
					),
				),
			)
		);
		$settings = $this->ok(
			'kuduy-elementor-get',
			array(
				'id'         => $page['id'],
				'element_id' => $element_id,
			)
		)['elements'][0]['settings'];
		$this->assertSame( 'KUDUY add-on edit', $settings[ $control ] ?? null, $widget['name'] . '.' . $control );

		$verify = $this->ok(
			'kuduy-elementor-verify',
			array(
				'id'         => $page['id'],
				'expect_ids' => array( $element_id ),
			)
		);
		$this->assertTrue( $verify['rendered'] );
		$this->assertSame( array(), $verify['missing_ids'], $widget['name'] . ' renders with its element id' );
	}
}
