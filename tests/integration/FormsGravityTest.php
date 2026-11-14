<?php
/**
 * Forms, Gravity Forms (test matrix F8, decision D28): forms, entries, read / starred / spam / trash with undo and
 * notes through GFAPI, Gravity's capabilities, personal data masked, passwords never shown. Gravity Forms is paid:
 * this runs against a stand-in for the documented GFAPI (KUDUY_FAKES=gravity), so it proves KuDiagnostics' side only.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class FormsGravityTest extends TestCase {

	use CallsTools;

	private const PIN = 'gravity-pin-4711';

	private static int $admin;
	private static int $editor;
	private static int $form;

	public static function setUpBeforeClass(): void {
		if ( ! class_exists( 'GFAPI' ) || ! in_array( 'add_test_form', get_class_methods( 'GFAPI' ), true ) ) {
			self::markTestSkipped( 'The Gravity Forms stand-in is not loaded in this run (KUDUY_FAKES=gravity).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$editor = self::make_user( 'editor' );
		self::$form   = \GFAPI::add_test_form(
			array(
				'title'         => 'Kuduy Gravity contact',
				'description'   => 'Contact us',
				'is_active'     => true,
				'fields'        => array(
					array(
						'id'         => 1,
						'type'       => 'name',
						'label'      => 'Name',
						'isRequired' => true,
						'inputs'     => array(
							array(
								'id'    => '1.3',
								'label' => 'First',
							),
							array(
								'id'    => '1.6',
								'label' => 'Last',
							),
						),
					),
					array(
						'id'    => 2,
						'type'  => 'email',
						'label' => 'Email',
					),
					array(
						'id'      => 3,
						'type'    => 'select',
						'label'   => 'Topic',
						'choices' => array(
							array(
								'text'  => 'Sales',
								'value' => 'sales',
							),
							array(
								'text'  => 'Support',
								'value' => 'support',
							),
						),
					),
					array(
						'id'    => 4,
						'type'  => 'textarea',
						'label' => 'Message',
					),
					array(
						'id'    => 5,
						'type'  => 'password',
						'label' => 'PIN',
					),
					array(
						'id'   => 6,
						'type' => 'html',
					),
				),
				'notifications' => array(
					'n1' => array(
						'name'    => 'Admin Notification',
						'to'      => 'office@example.test',
						'subject' => 'New entry',
						'bcc'     => 'archive@example.test',
					),
				),
				'confirmations' => array(
					'c1' => array(
						'type'    => 'message',
						'message' => '<p>Thanks.</p>',
					),
				),
			)
		);
		self::entry( 'Ann' );
		self::entry( 'Bob' );
		self::entry( 'Old', 'trash' );
	}

	/**
	 * An entry.
	 *
	 * @param string $name   Visitor.
	 * @param string $status Gravity status.
	 */
	private static function entry( string $name, string $status = 'active' ): int {
		return \GFAPI::add_test_entry(
			array(
				'form_id'    => self::$form,
				'status'     => $status,
				'1.3'        => $name,
				'1.6'        => 'Lee',
				'2'          => strtolower( $name ) . '@visitor.test',
				'3'          => 'sales',
				'4'          => 'Hello from ' . $name,
				'5'          => self::PIN,
				'ip'         => '203.0.113.9',
				'source_url' => 'https://example.test/contact/',
				'user_agent' => 'Firefox',
			)
		);
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	public function test_status_list_and_form(): void {
		$plugins = array_column( $this->ok( 'kuduy-forms-status' )['plugins'], null, 'plugin' );
		$this->assertSame( 'plugin', $plugins['gravityforms']['entries_store'] );
		$this->assertNull( $plugins['gravityforms']['abilities'] );

		$forms = array_column( $this->ok( 'kuduy-forms-list', array( 'plugin' => 'gravityforms' ) )['forms'], null, 'id' );
		$this->assertSame( 'Kuduy Gravity contact', $forms[ self::$form ]['title'] );
		$this->assertGreaterThanOrEqual( 2, $forms[ self::$form ]['entries'] );
		$this->assertSame( '[gravityform id="' . self::$form . '"]', $forms[ self::$form ]['shortcode'] );

		$form   = $this->ok(
			'kuduy-forms-get',
			array(
				'plugin'  => 'gravityforms',
				'form_id' => self::$form,
			)
		);
		$fields = array_column( $form['fields'], null, 'name' );
		$this->assertSame( array( '1', '2', '3', '4', '5' ), array_map( 'strval', array_keys( $fields ) ), 'The HTML block is not a field.' );
		$this->assertTrue( $fields['1']['required'] );
		$this->assertSame( array( 'sales', 'support' ), $fields['3']['choices'] );
		$this->assertArrayNotHasKey( 'kind', $fields['2'] );
		$this->assertSame( 'office@example.test', $form['notifications'][0]['recipient'] );

		wp_set_current_user( self::$editor );
		$this->assertSame( array(), $this->ok( 'kuduy-forms-list', array( 'plugin' => 'gravityforms' ) )['forms'], 'No Gravity capability, no forms.' );
		$this->fails(
			'kuduy-forms-get',
			array(
				'plugin'  => 'gravityforms',
				'form_id' => self::$form,
			),
			'editor without Gravity capability'
		);
	}

	public function test_entries_masked_and_passwords_never_shown(): void {
		$args = array(
			'plugin'  => 'gravityforms',
			'form_id' => self::$form,
		);
		$list = $this->ok( 'kuduy-forms-entries', $args );
		$this->assertGreaterThanOrEqual( 2, $list['total'] );
		$entry  = $list['entries'][0];
		$fields = array_column( $entry['fields'], null, 'name' );
		$this->assertSame( '[personal data]', $fields['1']['value'], 'The name is joined from its inputs and masked.' );
		$this->assertSame( '[personal data]', $fields['2']['value'] );
		$this->assertSame( 'sales', $fields['3']['value'] );
		$this->assertNull( $fields['5']['value'] );
		$this->assertTrue( $fields['5']['has_value'] );
		$this->assertSame( '[personal data]', $entry['meta']['ip'] );
		$this->assertStringNotContainsString( self::PIN, (string) wp_json_encode( $list ) );
		$this->assertGreaterThanOrEqual( 1, $this->ok( 'kuduy-forms-entries', $args + array( 'status' => 'trash' ) )['total'] );
		$this->assertSame( 1, $this->ok( 'kuduy-forms-entries', $args + array( 'search' => 'Hello from Bob' ) )['total'] );

		$full   = $this->ok(
			'kuduy-forms-entry-get',
			array(
				'plugin'      => 'gravityforms',
				'entry_id'    => (int) $entry['id'],
				'include_pii' => true,
			)
		);
		$values = array_column( $full['fields'], 'value', 'name' );
		$this->assertStringEndsWith( ' Lee', $values['1'] );
		$this->assertStringEndsWith( '@visitor.test', $values['2'] );
		$this->assertStringNotContainsString( self::PIN, (string) wp_json_encode( $full ) );

		wp_set_current_user( self::$editor );
		$this->fails( 'kuduy-forms-entries', $args, 'editor without Gravity capability' );
	}

	public function test_read_starred_spam_trash_and_notes_with_undo(): void {
		$id   = self::entry( 'Cyd' );
		$args = array(
			'plugin'   => 'gravityforms',
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
		$this->fails( 'kuduy-forms-entry-update', $args + array( 'status' => 'trash' ), 'editor without Gravity capability' );
		wp_set_current_user( self::$admin );
		$this->fails(
			'kuduy-forms-entry-get',
			array(
				'plugin'   => 'gravityforms',
				'entry_id' => 99999999,
			),
			'unknown entry'
		);
	}
}
