<?php
/**
 * Phase 5 Theme Builder (test matrix EL8): create a header template, list theme templates, set display
 * conditions through Elementor Pro's conditions manager, conflicts, undo, validation and permissions.
 * Runs only with Elementor Pro installed locally (KUDUY_LOCAL_PLUGINS); skips otherwise (CI has no Pro).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class ElementorThemeBuilderTest extends TestCase {

	use CallsTools;

	private static int $admin;
	private static int $editor;

	public static function setUpBeforeClass(): void {
		if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
			self::markTestSkipped( 'Elementor Pro is not active in this run (KUDUY_LOCAL_PLUGINS).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$editor = self::make_user( 'editor' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	/**
	 * A published header template with one heading.
	 *
	 * @param string $title Title.
	 * @return array<string, mixed>
	 */
	private function header( string $title ): array {
		return $this->ok(
			'kuduy-elementor-create',
			array(
				'title'         => $title,
				'template_type' => 'header',
				'status'        => 'publish',
				'elements'      => array(
					array(
						'elType'   => 'container',
						'elements' => array(
							array(
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => array( 'title' => $title ),
							),
						),
					),
				),
			)
		);
	}

	/**
	 * The listed template with this id.
	 *
	 * @param int $id Template id.
	 * @return array<string, mixed>
	 */
	private function listed( int $id ): array {
		foreach ( $this->ok( 'kuduy-elementor-theme-templates', array( 'type' => 'header' ) )['templates'] as $row ) {
			if ( $id === $row['id'] ) {
				return $row;
			}
		}
		$this->fail( 'Template ' . $id . ' is not listed.' );
	}

	public function test_create_list_and_set_conditions_with_conflicts_and_undo(): void {
		$first = $this->header( 'Kuduy header one' );
		$this->assertSame( 2, $first['element_count'] );
		$this->assertSame( 'header', get_post_meta( $first['id'], '_elementor_template_type', true ) );
		$this->assertSame( 'elementor_library', get_post_type( $first['id'] ) );

		$row = $this->listed( $first['id'] );
		$this->assertSame( 'header', $row['type'] );
		$this->assertSame( 'header', $row['location'] );
		$this->assertSame( array(), $row['conditions'] );
		$this->assertFalse( $row['live'], 'a template without conditions is not shown anywhere' );

		$set = $this->ok(
			'kuduy-elementor-theme-conditions',
			array(
				'id'         => $first['id'],
				'conditions' => array( 'include/general', 'exclude/singular/page/' . $first['id'] ),
			)
		);
		$this->assertSame( array( 'include/general', 'exclude/singular/page/' . $first['id'] ), $set['conditions'] );
		$this->assertTrue( $set['live'] );
		$this->assertSame( array( 'include/general', 'exclude/singular/page/' . $first['id'] ), get_post_meta( $first['id'], '_elementor_conditions', true ) );
		$this->assertTrue( $this->listed( $first['id'] )['live'] );

		// Same location, same condition: refused unless allowed.
		$second = $this->header( 'Kuduy header two' );
		$text   = $this->fails(
			'kuduy-elementor-theme-conditions',
			array(
				'id'         => $second['id'],
				'conditions' => array( 'include/general' ),
			),
			'a conflicting condition is refused'
		);
		$this->assertStringContainsString( (string) $first['id'], $text );
		$this->assertSame( '', get_post_meta( $second['id'], '_elementor_conditions', true ), 'nothing saved on refusal' );

		$allowed = $this->ok(
			'kuduy-elementor-theme-conditions',
			array(
				'id'              => $second['id'],
				'conditions'      => array( 'include/general' ),
				'allow_conflicts' => true,
			)
		);
		$this->assertSame( array( $first['id'] ), array_column( $allowed['conflicts'], 'template_id' ) );
		$this->assertSame( array( $first['id'] ), array_column( $this->listed( $second['id'] )['conflicts'], 'template_id' ) );

		// Undo puts the previous (empty) conditions back.
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $allowed['_snapshot_id'] ) );
		$this->assertSame( '', get_post_meta( $second['id'], '_elementor_conditions', true ) );
		$this->assertFalse( $this->listed( $second['id'] )['live'] );

		// An empty list removes all conditions.
		$cleared = $this->ok(
			'kuduy-elementor-theme-conditions',
			array(
				'id'         => $first['id'],
				'conditions' => array(),
			)
		);
		$this->assertSame( array(), $cleared['conditions'] );
		$this->assertFalse( $cleared['live'] );
	}

	public function test_invalid_conditions_and_non_templates_are_refused(): void {
		$header = $this->header( 'Kuduy header invalid' );
		foreach ( array( 'include/nowhere', 'include/general/page', 'maybe/general', 'include/singular/nowhere', 'include/singular/page/abc' ) as $bad ) {
			$this->fails(
				'kuduy-elementor-theme-conditions',
				array(
					'id'         => $header['id'],
					'conditions' => array( $bad ),
				),
				$bad . ' is refused'
			);
		}
		$page = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Kuduy not a template',
				'post_status' => 'draft',
			)
		);
		$this->fails(
			'kuduy-elementor-theme-conditions',
			array(
				'id'         => $page,
				'conditions' => array( 'include/general' ),
			),
			'a normal page is not a theme template'
		);
		$this->fails(
			'kuduy-elementor-create',
			array(
				'title'         => 'Kuduy bad type',
				'template_type' => 'nowhere',
			),
			'unknown template types are refused by the schema'
		);
	}

	public function test_editors_cannot_change_site_parts(): void {
		$header = $this->header( 'Kuduy header permissions' );
		wp_set_current_user( self::$editor );
		$this->fails(
			'kuduy-elementor-theme-conditions',
			array(
				'id'         => $header['id'],
				'conditions' => array( 'include/general' ),
			),
			'editors lack edit_theme_options'
		);
		$this->fails(
			'kuduy-elementor-create',
			array(
				'title'         => 'Kuduy editor header',
				'template_type' => 'footer',
			),
			'editors cannot create theme templates'
		);
		$this->assertSame( '', get_post_meta( $header['id'], '_elementor_conditions', true ) );
	}
}
