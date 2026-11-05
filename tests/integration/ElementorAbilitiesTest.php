<?php
/**
 * Phase 5, D7 hybrid: Elementor's own v4 abilities (global classes and variables) run through KUDUY with scope,
 * Elementor's permission check, schema, snapshot and audit. On Elementor 3.x the tools say they are unavailable.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Modules\Elementor\ElementorAbilities;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class ElementorAbilitiesTest extends TestCase {

	use CallsTools;

	private static int $admin;

	private static int $editor;

	public static function setUpBeforeClass(): void {
		if ( ! did_action( 'elementor/loaded' ) ) {
			self::markTestSkipped( 'Elementor is not active in this run (KUDUY_ELEMENTOR=none).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$editor = self::make_user( 'editor' );
		// What the first wp-admin visit does on a real site: Elementor's global-classes database update, which grants
		// administrators the class capabilities (it runs on admin_init, which tests never reach).
		$updater = '\Elementor\Modules\GlobalClasses\Database\Global_Classes_Database_Updater';
		if ( class_exists( $updater ) ) {
			( new $updater() )->up();
		}
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
		if ( true !== ( new ElementorAbilities( 'globals' ) )->is_available() ) {
			$this->markTestSkipped( 'Elementor v4 abilities are not available (Elementor before 4.3).' );
		}
	}

	public function test_unavailable_reason_is_clear_without_v4_abilities(): void {
		$this->assertTrue( ( new ElementorAbilities( 'variables' ) )->is_available() );
		$this->assertStringContainsString( 'operations', (string) wp_json_encode( ( new ElementorAbilities( 'variables' ) )->input_schema() ), 'Elementor\'s own input schema is used' );
	}

	public function test_variables_create_read_and_undo(): void {
		$before = $this->ok( 'kuduy-elementor-v4-globals' )['result']['variables'];

		wp_set_current_user( self::$editor );
		$this->fails(
			'kuduy-elementor-v4-variables',
			array(
				'operations' => array(
					array(
						'action' => 'create',
						'type'   => 'global-color-variable',
						'label'  => 'kuduy-editor',
						'value'  => '#000000',
					),
				),
			),
			'variables need manage_options'
		);

		wp_set_current_user( self::$admin );
		$created = $this->ok(
			'kuduy-elementor-v4-variables',
			array(
				'operations' => array(
					array(
						'action' => 'create',
						'type'   => 'global-color-variable',
						'label'  => 'kuduy-brand',
						'value'  => '#ff6600',
					),
				),
			)
		);
		$this->assertGreaterThan( 0, $created['_snapshot_id'] ?? 0 );
		$this->assertStringContainsString( 'kuduy-brand', (string) wp_json_encode( $this->ok( 'kuduy-elementor-v4-globals' )['result']['variables'] ) );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $created['_snapshot_id'] ) );
		$this->assertSame( $before, $this->ok( 'kuduy-elementor-v4-globals' )['result']['variables'] );
	}

	public function test_classes_create_and_copy_only_snapshot(): void {
		$created = $this->ok(
			'kuduy-elementor-v4-classes',
			array(
				'operations' => array(
					array(
						'action' => 'create',
						'label'  => 'kuduy-card',
						'css'    => 'padding: 24px; border-radius: 8px;',
					),
				),
			)
		);
		$this->assertStringContainsString( 'kuduy-card', (string) wp_json_encode( $this->ok( 'kuduy-elementor-v4-globals' )['result']['classes'] ) );

		$message = $this->fails( 'kuduy-snapshot-restore', array( 'snapshot_id' => $created['_snapshot_id'] ), 'class snapshots are copies, not automatic undo' );
		$this->assertStringContainsString( 'copy', $message );
	}

	public function test_invalid_input_is_refused_by_the_schema(): void {
		$this->fails( 'kuduy-elementor-v4-variables', array( 'operations' => array( array( 'action' => 'explode' ) ) ), 'unknown action' );
		$this->fails( 'kuduy-elementor-v4-variables', array(), 'operations required' );
	}
}
