<?php
/**
 * Phase 7 forms, Fluent Forms (test matrix F1–F5, F7; decisions D15–D17 A): forms and entries through Fluent's own
 * models, API and SubmissionService, Fluent's permissions, personal data masked by field kind, password fields never
 * shown, read / starred / spam / trash with undo, notes with undo. The form and its entries are written straight into
 * Fluent's tables as fixtures (the plugin's admin builds them the same way). Runs only when Fluent Forms is active
 * (KUDUY_PLUGINS=fluentform@…).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class FormsFluentTest extends TestCase {

	use CallsTools;

	private const PIN = 'fluent-pin-4711';

	private static int $admin;
	private static int $editor;
	private static int $form;

	public static function setUpBeforeClass(): void {
		global $wpdb;
		if ( ! function_exists( 'fluentFormApi' ) ) {
			self::markTestSkipped( 'Fluent Forms is not active in this run (KUDUY_PLUGINS).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$editor = self::make_user( 'editor' );
		$fields       = array(
			'fields'       => array(
				array(
					'element'    => 'input_name',
					'attributes' => array( 'name' => 'names' ),
					'settings'   => array( 'admin_field_label' => 'Name' ),
					'fields'     => array(
						'first_name' => array(
							'element'    => 'input_text',
							'attributes' => array( 'name' => 'first_name' ),
						),
					),
				),
				array(
					'element'    => 'input_email',
					'attributes' => array( 'name' => 'email' ),
					'settings'   => array(
						'label'            => 'Email',
						'validation_rules' => array( 'required' => array( 'value' => true ) ),
					),
				),
				array(
					'element'    => 'phone',
					'attributes' => array( 'name' => 'phone' ),
					'settings'   => array( 'label' => 'Phone' ),
				),
				array(
					'element'    => 'select',
					'attributes' => array( 'name' => 'topic' ),
					'settings'   => array(
						'label'            => 'Topic',
						'advanced_options' => array(
							array(
								'label' => 'Sales',
								'value' => 'sales',
							),
							array(
								'label' => 'Support',
								'value' => 'support',
							),
						),
					),
				),
				array(
					'element'    => 'textarea',
					'attributes' => array( 'name' => 'message' ),
					'settings'   => array( 'label' => 'Message' ),
				),
				array(
					'element'    => 'input_password',
					'attributes' => array( 'name' => 'pin' ),
					'settings'   => array( 'label' => 'PIN' ),
				),
				array(
					'element' => 'container',
					'columns' => array(
						array(
							'fields' => array(
								array(
									'element'    => 'input_text',
									'attributes' => array( 'name' => 'company' ),
									'settings'   => array( 'label' => 'Company' ),
								),
							),
						),
					),
				),
			),
			'submitButton' => array( 'element' => 'button' ),
		);
		$now          = current_time( 'mysql' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- test fixtures in Fluent Forms' own tables.
		$wpdb->insert(
			$wpdb->prefix . 'fluentform_forms',
			array(
				'title'       => 'Kuduy Fluent contact',
				'status'      => 'published',
				'form_fields' => (string) wp_json_encode( $fields ),
				'type'        => 'form',
				'created_by'  => self::$admin,
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		self::$form = (int) $wpdb->insert_id;
		$wpdb->insert(
			$wpdb->prefix . 'fluentform_form_meta',
			array(
				'form_id'  => self::$form,
				'meta_key' => 'notifications', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- fixture.
				'value'    => (string) wp_json_encode(
					array(
						'name'      => 'Admin Notification Email',
						'enabled'   => true,
						'sendTo'    => array(
							'type'  => 'email',
							'email' => 'office@example.test',
						),
						'fromName'  => 'Site',
						'fromEmail' => 'wordpress@example.test',
						'replyTo'   => '{inputs.email}',
						'bcc'       => 'archive@example.test',
						'subject'   => 'New submission',
					)
				),
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		self::entry( 'Ann', 'read' );
		self::entry( 'Bob', 'unread' );
		self::entry( 'Old', 'trashed' );
	}

	/**
	 * A Fluent submission row.
	 *
	 * @param string $name   Visitor name.
	 * @param string $status Fluent status.
	 */
	private static function entry( string $name, string $status = 'unread' ): int {
		global $wpdb;
		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture in Fluent Forms' own table.
		$wpdb->insert(
			$wpdb->prefix . 'fluentform_submissions',
			array(
				'form_id'       => self::$form,
				'serial_number' => wp_rand( 1, 99999 ),
				'response'      => (string) wp_json_encode(
					array(
						'names'                         => array( 'first_name' => $name ),
						'email'                         => strtolower( $name ) . '@visitor.test',
						'phone'                         => '+1 555 0100',
						'topic'                         => 'sales',
						'message'                       => 'Hello from ' . $name,
						'pin'                           => self::PIN,
						'company'                       => 'Acme',
						'_fluentform_1_fluentformnonce' => 'nonce',
					)
				),
				'source_url'    => 'https://example.test/contact/',
				'status'        => $status,
				'is_favourite'  => 0,
				'browser'       => 'Firefox',
				'device'        => 'Windows',
				'ip'            => '203.0.113.9',
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	public function test_status_list_and_form(): void {
		$plugins = array_column( $this->ok( 'kuduy-forms-status' )['plugins'], null, 'plugin' );
		$this->assertSame( FLUENTFORM_VERSION, $plugins['fluentform']['version'] );
		$this->assertSame( 'plugin', $plugins['fluentform']['entries_store'] );
		$this->assertFalse( $plugins['fluentform']['abilities'], 'Fluent\'s own abilities are off by default.' );

		$forms = array_column( $this->ok( 'kuduy-forms-list', array( 'plugin' => 'fluentform' ) )['forms'], null, 'id' );
		$this->assertSame( 'Kuduy Fluent contact', $forms[ self::$form ]['title'] );
		$this->assertGreaterThanOrEqual( 2, $forms[ self::$form ]['entries'], 'Trashed entries are not counted.' );
		$this->assertSame( '[fluentform id="' . self::$form . '"]', $forms[ self::$form ]['shortcode'] );

		$form   = $this->ok(
			'kuduy-forms-get',
			array(
				'plugin'  => 'fluentform',
				'form_id' => self::$form,
			)
		);
		$fields = array_column( $form['fields'], null, 'name' );
		$this->assertSame( array( 'names', 'email', 'phone', 'topic', 'message', 'pin', 'company' ), array_keys( $fields ), 'Container fields are flattened; the button is not a field.' );
		$this->assertTrue( $fields['email']['required'] );
		$this->assertSame( 'Name', $fields['names']['label'] );
		$this->assertSame( array( 'sales', 'support' ), $fields['topic']['choices'] );
		$this->assertArrayNotHasKey( 'kind', $fields['email'] );
		$this->assertSame( 'office@example.test', $form['notifications'][0]['recipient'] );
		$this->assertSame( 'archive@example.test', $form['notifications'][0]['bcc'] );

		wp_set_current_user( self::$editor );
		$this->assertSame( array(), $this->ok( 'kuduy-forms-list', array( 'plugin' => 'fluentform' ) )['forms'], 'No Fluent permission, no forms.' );
		$this->fails(
			'kuduy-forms-get',
			array(
				'plugin'  => 'fluentform',
				'form_id' => self::$form,
			),
			'editor without Fluent permission'
		);
	}

	public function test_entries_masked_and_passwords_never_shown(): void {
		$list = $this->ok(
			'kuduy-forms-entries',
			array(
				'plugin'  => 'fluentform',
				'form_id' => self::$form,
			)
		);
		$this->assertGreaterThanOrEqual( 2, $list['total'] );
		foreach ( $list['entries'] as $entry ) {
			$this->assertNotSame( 'trash', $entry['status'], 'Default status filter is active.' );
		}
		$entry  = $list['entries'][0];
		$fields = array_column( $entry['fields'], null, 'name' );
		$this->assertSame( '[personal data]', $fields['email']['value'] );
		$this->assertSame( '[personal data]', $fields['phone']['value'] );
		$this->assertSame( '[personal data]', $fields['names']['value'] );
		$this->assertSame( 'sales', $fields['topic']['value'] );
		$this->assertStringStartsWith( 'Hello from', $fields['message']['value'] );
		$this->assertNull( $fields['pin']['value'] );
		$this->assertTrue( $fields['pin']['has_value'] );
		$this->assertArrayNotHasKey( '_fluentform_1_fluentformnonce', $fields, 'Internal keys are not fields.' );
		$this->assertSame( '[personal data]', $entry['meta']['ip'] );
		$this->assertStringNotContainsString( self::PIN, (string) wp_json_encode( $list ) );

		$trash = $this->ok(
			'kuduy-forms-entries',
			array(
				'plugin'  => 'fluentform',
				'form_id' => self::$form,
				'status'  => 'trash',
			)
		);
		$this->assertGreaterThanOrEqual( 1, $trash['total'] );

		$full = $this->ok(
			'kuduy-forms-entry-get',
			array(
				'plugin'      => 'fluentform',
				'entry_id'    => (int) $entry['id'],
				'include_pii' => true,
			)
		);
		$this->assertStringEndsWith( '@visitor.test', array_column( $full['fields'], 'value', 'name' )['email'] );
		$this->assertSame( '203.0.113.9', $full['meta']['ip'] );
		$this->assertStringNotContainsString( self::PIN, (string) wp_json_encode( $full ), 'Not even with full data.' );

		wp_set_current_user( self::$editor );
		$this->fails(
			'kuduy-forms-entries',
			array(
				'plugin'  => 'fluentform',
				'form_id' => self::$form,
			),
			'editor without Fluent permission'
		);
	}

	public function test_read_starred_spam_trash_with_undo(): void {
		$id   = self::entry( 'Cyd', 'unread' );
		$args = array(
			'plugin'   => 'fluentform',
			'entry_id' => $id,
		);
		$read = $this->ok( 'kuduy-forms-entry-update', $args + array( 'read' => true, 'starred' => true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- two flags.
		$this->assertTrue( $read['done'] );
		$this->assertTrue( $read['entry']['read'] );
		$this->assertTrue( $read['entry']['starred'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $read['_snapshot_id'] ) );
		$state = $this->ok( 'kuduy-forms-entry-get', $args );
		$this->assertFalse( $state['read'] );
		$this->assertFalse( $state['starred'] );

		$trash = $this->ok( 'kuduy-forms-entry-update', $args + array( 'status' => 'trash' ) );
		$this->assertSame( 'trash', $trash['entry']['status'] );
		$this->fails( 'kuduy-forms-entry-update', $args + array( 'read' => true ), 'read flag on a trashed entry' );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $trash['_snapshot_id'] ) );
		$state = $this->ok( 'kuduy-forms-entry-get', $args );
		$this->assertSame( 'active', $state['status'] );
		$this->assertFalse( $state['read'], 'Undo brings back "unread", not just "active".' );

		$spam = $this->ok( 'kuduy-forms-entry-update', $args + array( 'status' => 'spam' ) );
		$this->assertTrue( $spam['done'] );
		$this->assertSame( 'spam', $spam['entry']['status'] );
		$this->assertSame( array(), $spam['impact'], 'Fluent keeps spam on the site.' );
		$this->ok( 'kuduy-forms-entry-update', $args + array( 'status' => 'active' ) );
		$this->assertSame( 'active', $this->ok( 'kuduy-forms-entry-get', $args )['status'] );

		wp_set_current_user( self::$editor );
		$this->fails( 'kuduy-forms-entry-update', $args + array( 'status' => 'trash' ), 'editor without Fluent permission' );
	}

	public function test_note_with_undo_and_no_text_in_audit(): void {
		$id    = self::entry( 'Dee' );
		$text  = 'Called Dee back on 555 0100';
		$added = $this->ok(
			'kuduy-forms-entry-note',
			array(
				'plugin'   => 'fluentform',
				'entry_id' => $id,
				'note'     => $text,
			)
		);
		$notes = $this->ok(
			'kuduy-forms-entry-get',
			array(
				'plugin'   => 'fluentform',
				'entry_id' => $id,
			)
		)['notes'];
		$this->assertSame( $text, $notes[0]['text'] );
		$this->assertSame( $added['note_id'], $notes[0]['id'] );
		$this->assertStringNotContainsString( $text, (string) wp_json_encode( AuditLog::latest( 5 ) ) );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $added['_snapshot_id'] ) );
		$this->assertSame(
			array(),
			$this->ok(
				'kuduy-forms-entry-get',
				array(
					'plugin'   => 'fluentform',
					'entry_id' => $id,
				)
			)['notes']
		);
	}

	public function test_refusals(): void {
		$this->fails(
			'kuduy-forms-entry-get',
			array(
				'plugin'   => 'fluentform',
				'entry_id' => 99999999,
			),
			'unknown entry'
		);
		$this->fails(
			'kuduy-forms-entries',
			array(
				'plugin'  => 'fluentform',
				'form_id' => 99999999,
			),
			'unknown form'
		);
		$this->assertNull( \Kuduy\Mcp\Mcp\ToolRegistry::create()->get( 'kuduy-forms-entry-delete' ), 'Permanent delete is not offered.' );
	}
}
