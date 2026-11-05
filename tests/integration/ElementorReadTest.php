<?php
/**
 * Phase 5 read pass on real Elementor (test matrix EL1, EL2, EL7): documents, element tree, unregistered types,
 * widget schemas (v3 controls per breakpoint, v4 props), Kit.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class ElementorReadTest extends TestCase {

	use CallsTools;

	private static int $admin;

	private static int $author;

	private static int $subscriber;

	private static int $page;

	private static int $broken;

	public static function setUpBeforeClass(): void {
		if ( ! did_action( 'elementor/loaded' ) ) {
			self::markTestSkipped( 'Elementor is not active in this run (KUDUY_ELEMENTOR=none).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin      = self::make_user( 'administrator' );
		self::$author     = self::make_user( 'author' );
		self::$subscriber = self::make_user( 'subscriber' );

		self::$page   = self::elementor_page(
			'Kuduy Elementor page',
			array(
				array(
					'id'       => 'c0ffee1',
					'elType'   => 'container',
					'settings' => array( 'content_width' => 'boxed' ),
					'isInner'  => false,
					'elements' => array(
						array(
							'id'         => 'beef001',
							'elType'     => 'widget',
							'widgetType' => 'heading',
							'settings'   => array(
								'title'        => 'Hello from KUDUY',
								'align'        => 'center',
								'align_mobile' => 'left',
							),
							'elements'   => array(),
							'isInner'    => false,
						),
					),
				),
			)
		);
		self::$broken = self::elementor_page(
			'Kuduy page with a missing add-on',
			array(
				array(
					'id'         => 'dead001',
					'elType'     => 'widget',
					'widgetType' => 'kuduy-missing-addon-widget',
					'settings'   => array(),
					'elements'   => array(),
					'isInner'    => false,
				),
			)
		);
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	/**
	 * A page stored the way Elementor stores it (fixture; the write tools will use Document::save()).
	 *
	 * @param string                     $title    Title.
	 * @param list<array<string, mixed>> $elements Elements.
	 */
	private static function elementor_page( string $title, array $elements ): int {
		$id = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_author' => self::$admin,
			)
		);
		update_post_meta( $id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $id, '_elementor_version', (string) constant( 'ELEMENTOR_VERSION' ) );
		update_post_meta( $id, '_elementor_data', wp_slash( (string) wp_json_encode( $elements ) ) );
		return $id;
	}

	public function test_documents_list_and_permissions(): void {
		$docs = $this->ok( 'kuduy-elementor-documents', array( 'search' => 'Kuduy' ) );
		$this->assertContains( self::$page, array_column( $docs['documents'], 'id' ) );
		$this->assertSame( 'wp-page', array_column( $docs['documents'], 'type', 'id' )[ self::$page ] );

		wp_set_current_user( self::$subscriber );
		$this->fails( 'kuduy-elementor-documents', array(), 'subscribers cannot edit' );
	}

	public function test_get_returns_tree_outline_subtree_and_checks_edit_post(): void {
		$doc = $this->ok( 'kuduy-elementor-get', array( 'id' => self::$page ) );
		$this->assertSame( 2, $doc['element_count'] );
		$this->assertSame( array(), $doc['unregistered'] );
		$this->assertSame( 'Hello from KUDUY', $doc['elements'][0]['elements'][0]['settings']['title'] );

		$outline = $this->ok(
			'kuduy-elementor-get',
			array(
				'id'               => self::$page,
				'include_settings' => false,
			)
		);
		$this->assertArrayNotHasKey( 'settings', $outline['elements'][0] );
		$this->assertSame( 'heading', $outline['elements'][0]['elements'][0]['widgetType'] );

		$sub = $this->ok(
			'kuduy-elementor-get',
			array(
				'id'         => self::$page,
				'element_id' => 'beef001',
			)
		);
		$this->assertSame( 'beef001', $sub['elements'][0]['id'] );

		wp_set_current_user( self::$author );
		$this->fails( 'kuduy-elementor-get', array( 'id' => self::$page ), 'authors cannot open someone else\'s page' );
	}

	public function test_get_names_unregistered_element_types(): void {
		$doc = $this->ok( 'kuduy-elementor-get', array( 'id' => self::$broken ) );
		$this->assertSame( array( 'kuduy-missing-addon-widget' ), $doc['unregistered'] );
	}

	public function test_widgets_list_types_and_sources(): void {
		$widgets = array_column( $this->ok( 'kuduy-elementor-widgets' )['widgets'], null, 'name' );
		$this->assertArrayHasKey( 'heading', $widgets );
		$this->assertFalse( $widgets['heading']['atomic'] );
		$this->assertSame( 'elementor', $widgets['heading']['source'] );
		if ( isset( $widgets['e-heading'] ) ) {
			$this->assertTrue( $widgets['e-heading']['atomic'] );
		}
	}

	public function test_v3_schema_expands_responsive_controls(): void {
		$schema = $this->ok( 'kuduy-elementor-widget-schema', array( 'type' => 'heading' ) );
		$this->assertFalse( $schema['atomic'] );
		$controls = array_column( $schema['controls'], null, 'name' );
		$this->assertArrayHasKey( 'title', $controls );
		$this->assertContains( 'align_mobile', $controls['align']['device_keys'] ?? array(), 'style controls (with CSS selectors) are listed too' );
		$this->assertContains( 'align', $controls['align']['device_keys'] ?? array(), 'desktop key has no suffix' );
		$this->assertArrayNotHasKey( 'align_mobile', $controls, 'per-device copies are listed under the base control' );

		$this->fails( 'kuduy-elementor-widget-schema', array( 'type' => 'nope-widget' ), 'unknown type' );
	}

	public function test_v4_schema_uses_prop_types_when_atomic_widgets_exist(): void {
		$widgets = array_column( $this->ok( 'kuduy-elementor-widgets' )['widgets'], null, 'name' );
		if ( ! isset( $widgets['e-heading'] ) ) {
			$this->markTestSkipped( 'Atomic widgets are not active on this Elementor setup.' );
		}
		$schema = $this->ok( 'kuduy-elementor-widget-schema', array( 'type' => 'e-heading' ) );
		$this->assertTrue( $schema['atomic'] );
		$this->assertStringContainsString( '"$$type"', (string) wp_json_encode( $schema['props']['tag'] ), 'v4 values use the {$$type, value} envelope' );
		$this->assertArrayHasKey( 'anyOf', $schema['props']['tag'], 'a plain value or an overridable one' );
	}

	public function test_kit_global_styles_and_breakpoints(): void {
		$kit = $this->ok( 'kuduy-elementor-kit' );
		$this->assertGreaterThan( 0, $kit['kit_id'] );
		$this->assertArrayHasKey( 'mobile', $kit['breakpoints'] );
		$this->assertIsArray( $kit['colors'] );
	}
}
