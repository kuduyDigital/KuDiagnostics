<?php
/**
 * Phase 7 forms, WPForms Lite (test matrix F1, F2, F7; decision D15 A): forms through WPForms' own form handler and
 * permission check, notifications and confirmations, and a clear refusal for entries (Lite keeps none on the site).
 * Runs only when WPForms is active (KUDUY_PLUGINS=wpforms-lite@…).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class FormsWpformsTest extends TestCase {

	use CallsTools;

	private static int $admin;
	private static int $editor;
	private static int $form;

	public static function setUpBeforeClass(): void {
		if ( ! function_exists( 'wpforms' ) ) {
			self::markTestSkipped( 'WPForms is not active in this run (KUDUY_PLUGINS).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$editor = self::make_user( 'editor' );
		wp_set_current_user( self::$admin );
		$id   = (int) wp_insert_post(
			array(
				'post_type'   => 'wpforms',
				'post_status' => 'publish',
				'post_title'  => 'Kuduy WPForms contact',
			)
		);
		$data = array(
			'id'       => $id,
			'field_id' => 4,
			'fields'   => array(
				'1' => array(
					'id'       => '1',
					'type'     => 'name',
					'label'    => 'Name',
					'required' => '1',
				),
				'2' => array(
					'id'    => '2',
					'type'  => 'email',
					'label' => 'Email',
				),
				'3' => array(
					'id'      => '3',
					'type'    => 'select',
					'label'   => 'Topic',
					'choices' => array(
						'1' => array( 'label' => 'Sales' ),
						'2' => array( 'label' => 'Support' ),
					),
				),
			),
			'settings' => array(
				'form_title'          => 'Kuduy WPForms contact',
				'submit_text'         => 'Send',
				'notification_enable' => '1',
				'notifications'       => array(
					'1' => array(
						'notification_name' => 'Default Notification',
						'email'             => 'office@example.test',
						'subject'           => 'New entry',
						'sender_name'       => 'Site',
						'sender_address'    => 'wordpress@example.test',
						'replyto'           => '{field_id="2"}',
					),
				),
				'confirmations'       => array(
					'1' => array(
						'type'    => 'message',
						'message' => '<p>Thanks for writing.</p>',
					),
				),
			),
		);
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => wp_slash( (string) wp_json_encode( $data ) ),
			)
		);
		self::$form = $id;
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	public function test_status_list_and_form(): void {
		$plugins = array_column( $this->ok( 'kuduy-forms-status' )['plugins'], null, 'plugin' );
		$this->assertSame( WPFORMS_VERSION, $plugins['wpforms']['version'] );
		$this->assertNull( $plugins['wpforms']['entries_store'], 'WPForms Lite keeps no entries on the site.' );

		$forms = array_column( $this->ok( 'kuduy-forms-list', array( 'plugin' => 'wpforms' ) )['forms'], null, 'id' );
		$this->assertSame( 'Kuduy WPForms contact', $forms[ self::$form ]['title'] );
		$this->assertNull( $forms[ self::$form ]['entries'] );

		$form   = $this->ok(
			'kuduy-forms-get',
			array(
				'plugin'  => 'wpforms',
				'form_id' => self::$form,
			)
		);
		$fields = array_column( $form['fields'], null, 'label' );
		$this->assertTrue( $fields['Name']['required'] );
		$this->assertSame( 'email', $fields['Email']['type'] );
		$this->assertSame( array( 'Sales', 'Support' ), $fields['Topic']['choices'] );
		$this->assertSame( 'office@example.test', $form['notifications'][0]['recipient'] );
		$this->assertTrue( $form['notifications'][0]['active'] );
		$this->assertSame( 'Thanks for writing.', $form['messages']['confirmation_1'] );

		wp_set_current_user( self::$editor );
		$this->assertSame( array(), $this->ok( 'kuduy-forms-list', array( 'plugin' => 'wpforms' ) )['forms'], 'WPForms Lite needs manage_options.' );
		$this->fails(
			'kuduy-forms-get',
			array(
				'plugin'  => 'wpforms',
				'form_id' => self::$form,
			),
			'editor in WPForms Lite'
		);
	}

	public function test_entries_are_refused_with_the_reason(): void {
		$message = $this->fails(
			'kuduy-forms-entries',
			array(
				'plugin'  => 'wpforms',
				'form_id' => self::$form,
			),
			'Lite keeps no entries'
		);
		$this->assertStringContainsString( 'keeps no entries', $message );
	}
}
