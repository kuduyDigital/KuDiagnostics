<?php
/**
 * Forms, Ninja Forms (test matrix F10, decision D28): forms, fields, actions and submissions through Ninja Forms' own
 * model factory on the real plugin; trash and restore with undo; personal data masked, passwords never shown; clear
 * refusals for what Ninja Forms does not have (read, starred, spam, notes). Runs only when Ninja Forms is active
 * (KUDUY_PLUGINS=ninja-forms@…).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class FormsNinjaTest extends TestCase {

	use CallsTools;

	private const PIN = 'ninja-pin-4711';

	private static int $admin;
	private static int $editor;
	private static int $form;

	/**
	 * Field ids by key.
	 *
	 * @var array<string, int>
	 */
	private static array $fields = array();

	public static function setUpBeforeClass(): void {
		if ( ! function_exists( 'Ninja_Forms' ) ) {
			self::markTestSkipped( 'Ninja Forms is not active in this run (KUDUY_PLUGINS).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$editor = self::make_user( 'editor' );
		wp_set_current_user( self::$admin );
		$form = Ninja_Forms()->form()->get();
		$form->update_settings( array( 'title' => 'Kuduy Ninja contact' ) )->save();
		self::$form = (int) $form->get_id();
		foreach ( array(
			'name'    => array( 'textbox', 'Name' ),
			'email'   => array( 'email', 'Email' ),
			'topic'   => array( 'listselect', 'Topic' ),
			'message' => array( 'textarea', 'Message' ),
			'pin'     => array( 'password', 'PIN' ),
			'send'    => array( 'submit', 'Send' ),
		) as $key => $def ) {
			$field    = Ninja_Forms()->form( self::$form )->field()->get();
			$settings = array(
				'type'     => $def[0],
				'label'    => $def[1],
				'key'      => $key,
				'required' => 'email' === $key ? 1 : 0,
			);
			if ( 'topic' === $key ) {
				$settings['options'] = array(
					array(
						'label' => 'Sales',
						'value' => 'sales',
					),
					array(
						'label' => 'Support',
						'value' => 'support',
					),
				);
			}
			$field->update_settings( $settings )->save();
			self::$fields[ $key ] = (int) $field->get_id();
		}//end foreach
		$action = Ninja_Forms()->form( self::$form )->action()->get();
		$action->update_settings(
			array(
				'type'          => 'email',
				'label'         => 'Admin Email',
				'active'        => 1,
				'to'            => 'office@example.test',
				'email_subject' => 'New submission',
				'bcc'           => 'archive@example.test',
			)
		)->save();
		self::entry( 'Ann' );
		self::entry( 'Bob' );
	}

	/**
	 * A submission saved through Ninja Forms' own model.
	 *
	 * @param string $name Visitor.
	 */
	private static function entry( string $name ): int {
		$sub = Ninja_Forms()->form( self::$form )->sub()->get();
		$sub->update_field_value( self::$fields['name'], $name . ' Lee' );
		$sub->update_field_value( self::$fields['email'], strtolower( $name ) . '@visitor.test' );
		$sub->update_field_value( self::$fields['topic'], 'sales' );
		$sub->update_field_value( self::$fields['message'], 'Hello from ' . $name );
		$sub->update_field_value( self::$fields['pin'], self::PIN );
		$sub->save();
		return (int) $sub->get_id();
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	public function test_status_list_and_form(): void {
		$plugins = array_column( $this->ok( 'kuduy-forms-status' )['plugins'], null, 'plugin' );
		$this->assertSame( \Ninja_Forms::VERSION, $plugins['ninjaforms']['version'] );
		$this->assertSame( 'plugin', $plugins['ninjaforms']['entries_store'] );

		$forms = array_column( $this->ok( 'kuduy-forms-list', array( 'plugin' => 'ninjaforms' ) )['forms'], null, 'id' );
		$this->assertSame( 'Kuduy Ninja contact', $forms[ self::$form ]['title'] );
		$this->assertGreaterThanOrEqual( 2, $forms[ self::$form ]['entries'] );
		$this->assertSame( '[ninja_form id="' . self::$form . '"]', $forms[ self::$form ]['shortcode'] );

		$form   = $this->ok(
			'kuduy-forms-get',
			array(
				'plugin'  => 'ninjaforms',
				'form_id' => self::$form,
			)
		);
		$fields = array_column( $form['fields'], null, 'name' );
		$this->assertSame( array( 'name', 'email', 'topic', 'message', 'pin' ), array_keys( $fields ), 'The submit button is not a field.' );
		$this->assertTrue( $fields['email']['required'] );
		$this->assertSame( array( 'sales', 'support' ), $fields['topic']['choices'] );
		$this->assertArrayNotHasKey( 'kind', $fields['email'] );
		$this->assertSame( 'office@example.test', $form['notifications'][0]['recipient'] );
		$this->assertSame( 'archive@example.test', $form['notifications'][0]['bcc'] );

		wp_set_current_user( self::$editor );
		$this->assertSame( array(), $this->ok( 'kuduy-forms-list', array( 'plugin' => 'ninjaforms' ) )['forms'], 'Ninja Forms needs manage_options.' );
		$this->fails(
			'kuduy-forms-get',
			array(
				'plugin'  => 'ninjaforms',
				'form_id' => self::$form,
			),
			'editor'
		);
	}

	public function test_entries_masked_and_passwords_never_shown(): void {
		$args = array(
			'plugin'  => 'ninjaforms',
			'form_id' => self::$form,
		);
		$list = $this->ok( 'kuduy-forms-entries', $args );
		$this->assertGreaterThanOrEqual( 2, $list['total'] );
		$entry  = $list['entries'][0];
		$fields = array_column( $entry['fields'], null, 'name' );
		$this->assertSame( '[personal data]', $fields['name']['value'] );
		$this->assertSame( '[personal data]', $fields['email']['value'] );
		$this->assertSame( 'sales', $fields['topic']['value'] );
		$this->assertStringStartsWith( 'Hello from', $fields['message']['value'] );
		$this->assertNull( $fields['pin']['value'] );
		$this->assertTrue( $fields['pin']['has_value'] );
		$this->assertStringNotContainsString( self::PIN, (string) wp_json_encode( $list ) );
		$this->assertSame( 1, $this->ok( 'kuduy-forms-entries', $args + array( 'search' => 'Hello from Bob' ) )['total'] );
		$this->assertSame( 0, $this->ok( 'kuduy-forms-entries', $args + array( 'status' => 'spam' ) )['total'], 'Ninja Forms has no spam folder.' );

		$full = $this->ok(
			'kuduy-forms-entry-get',
			array(
				'plugin'      => 'ninjaforms',
				'entry_id'    => (int) $entry['id'],
				'include_pii' => true,
			)
		);
		$this->assertStringEndsWith( '@visitor.test', array_column( $full['fields'], 'value', 'name' )['email'] );
		$this->assertStringNotContainsString( self::PIN, (string) wp_json_encode( $full ), 'Not even with full data.' );

		wp_set_current_user( self::$editor );
		$this->fails( 'kuduy-forms-entries', $args, 'editor' );
	}

	public function test_trash_and_restore_with_undo_and_refusals(): void {
		$id    = self::entry( 'Cyd' );
		$args  = array(
			'plugin'   => 'ninjaforms',
			'entry_id' => $id,
		);
		$trash = $this->ok( 'kuduy-forms-entry-update', $args + array( 'status' => 'trash' ) );
		$this->assertSame( 'trash', $trash['entry']['status'] );
		$this->assertSame( 'trash', get_post_status( $id ) );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $trash['_snapshot_id'] ) );
		$this->assertSame( 'publish', get_post_status( $id ), 'Undo puts the submission back as it was, not as a draft.' );
		$this->ok( 'kuduy-forms-entry-update', $args + array( 'status' => 'trash' ) );
		$this->assertSame( 'active', $this->ok( 'kuduy-forms-entry-update', $args + array( 'status' => 'active' ) )['entry']['status'] );
		$this->assertSame( 'publish', get_post_status( $id ) );

		$this->fails( 'kuduy-forms-entry-update', $args + array( 'read' => true ), 'no read flag in Ninja Forms' );
		$this->fails( 'kuduy-forms-entry-update', $args + array( 'status' => 'spam' ), 'no spam folder in Ninja Forms' );
		$this->fails( 'kuduy-forms-entry-note', $args + array( 'note' => 'x' ), 'no notes in Ninja Forms' );
		$this->assertSame( 'publish', get_post_field( 'post_status', $id ) );
		$this->fails(
			'kuduy-forms-entry-get',
			array(
				'plugin'   => 'ninjaforms',
				'entry_id' => self::$form + 99999999,
			),
			'unknown submission'
		);
		wp_set_current_user( self::$editor );
		$this->fails( 'kuduy-forms-entry-update', $args + array( 'status' => 'trash' ), 'editor' );
	}
}
