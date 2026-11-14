<?php
/**
 * Forms, WPForms Pro entries (test matrix F9, decision D28): entries, read / starred / spam / trash with undo and
 * notes through WPForms' entry handlers, on the real WPForms Lite with a stand-in for Pro's entry storage
 * (KUDUY_PLUGINS=wpforms-lite@… KUDUY_FAKES=wpforms-pro). WPForms Pro is paid: this proves KuDiagnostics' side only.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class FormsWpformsProTest extends TestCase {

	use CallsTools;

	private const PIN = 'wpforms-pin-4711';

	private static int $admin;
	private static int $editor;
	private static int $form;

	public static function setUpBeforeClass(): void {
		if ( ! function_exists( 'wpforms' ) || ! class_exists( 'Kuduy_Fake_Wpforms_Entry' ) ) {
			self::markTestSkipped( 'WPForms with the Pro entries stand-in is not loaded in this run (KUDUY_FAKES=wpforms-pro).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$editor = self::make_user( 'editor' );
		self::$form   = (int) wp_insert_post(
			array(
				'post_type'    => 'wpforms',
				'post_status'  => 'publish',
				'post_title'   => 'Kuduy WPForms Pro contact',
				'post_content' => '{"fields":[],"settings":{}}',
			)
		);
		self::entry( 'Ann' );
		self::entry( 'Bob' );
		self::entry( 'Old', 'trash' );
	}

	/**
	 * An entry row as WPForms stores field values: a JSON list of id, name (label), value, type.
	 *
	 * @param string $name   Visitor.
	 * @param string $status WPForms status ('' = normal).
	 */
	private static function entry( string $name, string $status = '' ): int {
		$fields = array(
			'1' => array(
				'id'    => 1,
				'name'  => 'Name',
				'value' => $name . ' Lee',
				'type'  => 'name',
			),
			'2' => array(
				'id'    => 2,
				'name'  => 'Email',
				'value' => strtolower( $name ) . '@visitor.test',
				'type'  => 'email',
			),
			'3' => array(
				'id'    => 3,
				'name'  => 'Topic',
				'value' => 'Sales',
				'type'  => 'select',
			),
			'4' => array(
				'id'    => 4,
				'name'  => 'PIN',
				'value' => self::PIN,
				'type'  => 'password',
			),
		);
		return (int) wpforms()->obj( 'entry' )->add(
			array(
				'form_id'    => self::$form,
				'status'     => $status,
				'fields'     => (string) wp_json_encode( $fields ),
				'ip_address' => '203.0.113.9',
				'user_agent' => 'Firefox',
			)
		);
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	public function test_entries_masked_and_passwords_never_shown(): void {
		$plugins = array_column( $this->ok( 'kuduy-forms-status' )['plugins'], null, 'plugin' );
		$this->assertSame( 'plugin', $plugins['wpforms']['entries_store'] );
		$forms = array_column( $this->ok( 'kuduy-forms-list', array( 'plugin' => 'wpforms' ) )['forms'], null, 'id' );
		$this->assertGreaterThanOrEqual( 2, $forms[ self::$form ]['entries'] );

		$args = array(
			'plugin'  => 'wpforms',
			'form_id' => self::$form,
		);
		$list = $this->ok( 'kuduy-forms-entries', $args );
		$this->assertGreaterThanOrEqual( 2, $list['total'] );
		foreach ( $list['entries'] as $entry ) {
			$this->assertSame( 'active', $entry['status'] );
		}
		$fields = array_column( $list['entries'][0]['fields'], null, 'label' );
		$this->assertSame( '[personal data]', $fields['Name']['value'] );
		$this->assertSame( '[personal data]', $fields['Email']['value'] );
		$this->assertSame( 'Sales', $fields['Topic']['value'] );
		$this->assertNull( $fields['PIN']['value'] );
		$this->assertSame( '[personal data]', $list['entries'][0]['meta']['ip'] );
		$this->assertStringNotContainsString( self::PIN, (string) wp_json_encode( $list ) );
		$this->assertGreaterThanOrEqual( 1, $this->ok( 'kuduy-forms-entries', $args + array( 'status' => 'trash' ) )['total'] );
		$this->fails( 'kuduy-forms-entries', $args + array( 'search' => 'Ann' ), 'search is not supported for WPForms' );

		$full = $this->ok(
			'kuduy-forms-entry-get',
			array(
				'plugin'      => 'wpforms',
				'entry_id'    => (int) $list['entries'][0]['id'],
				'include_pii' => true,
			)
		);
		$this->assertStringEndsWith( '@visitor.test', array_column( $full['fields'], 'value', 'label' )['Email'] );
		$this->assertStringNotContainsString( self::PIN, (string) wp_json_encode( $full ) );

		wp_set_current_user( self::$editor );
		$this->fails( 'kuduy-forms-entries', $args, 'editor without WPForms permission' );
	}

	public function test_read_starred_spam_trash_and_notes_with_undo(): void {
		$id   = self::entry( 'Cyd' );
		$args = array(
			'plugin'   => 'wpforms',
			'entry_id' => $id,
		);
		$read = $this->ok( 'kuduy-forms-entry-update', $args + array( 'read' => true, 'starred' => true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- two flags.
		$this->assertTrue( $read['entry']['read'] );
		$this->assertTrue( $read['entry']['starred'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $read['_snapshot_id'] ) );
		$state = $this->ok( 'kuduy-forms-entry-get', $args );
		$this->assertFalse( $state['read'] );
		$this->assertFalse( $state['starred'] );

		$trash = $this->ok( 'kuduy-forms-entry-update', $args + array( 'status' => 'trash' ) );
		$this->assertSame( 'trash', $trash['entry']['status'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $trash['_snapshot_id'] ) );
		$this->assertSame( 'active', $this->ok( 'kuduy-forms-entry-get', $args )['status'] );
		$this->assertSame( 'spam', $this->ok( 'kuduy-forms-entry-update', $args + array( 'status' => 'spam' ) )['entry']['status'] );
		$this->ok( 'kuduy-forms-entry-update', $args + array( 'status' => 'active' ) );

		$added = $this->ok( 'kuduy-forms-entry-note', $args + array( 'note' => 'Called back' ) );
		$notes = $this->ok( 'kuduy-forms-entry-get', $args )['notes'];
		$this->assertSame( 'Called back', $notes[0]['text'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $added['_snapshot_id'] ) );
		$this->assertSame( array(), $this->ok( 'kuduy-forms-entry-get', $args )['notes'] );

		wp_set_current_user( self::$editor );
		$this->fails( 'kuduy-forms-entry-update', $args + array( 'status' => 'trash' ), 'editor without WPForms permission' );
	}
}
