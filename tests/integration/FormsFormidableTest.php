<?php
/**
 * Forms, Formidable Forms (test matrix F11, decision D28): forms, fields and entries through Formidable's own model
 * classes on the real plugin; personal data masked, passwords never shown; entries are read only, and every change
 * is refused with the reason. Runs only when Formidable is active (KUDUY_PLUGINS=formidable@…).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class FormsFormidableTest extends TestCase {

	use CallsTools;

	private const PIN = 'formidable-pin-4711';

	private static int $admin;
	private static int $editor;
	private static int $form;

	/**
	 * Field ids by name.
	 *
	 * @var array<string, int>
	 */
	private static array $fields = array();

	public static function setUpBeforeClass(): void {
		if ( ! class_exists( 'FrmForm' ) ) {
			self::markTestSkipped( 'Formidable Forms is not active in this run (KUDUY_PLUGINS).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$editor = self::make_user( 'editor' );
		wp_set_current_user( self::$admin );
		// Creates Formidable's tables when the plugin was activated without its admin screens.
		\FrmAppController::install();
		self::$form = (int) \FrmForm::create(
			array(
				'name'        => 'Kuduy Formidable contact',
				'description' => 'Contact us',
				'status'      => 'published',
				'options'     => array(
					'submit_value' => 'Send',
					'success_msg'  => 'Thanks for writing.',
				),
			)
		);
		foreach ( array(
			'Name'    => 'text',
			'Email'   => 'email',
			'Topic'   => 'select',
			'Message' => 'textarea',
			'PIN'     => 'password',
		) as $label => $type ) {
			$values         = \FrmFieldsHelper::setup_new_vars( $type, self::$form );
			$values['name'] = $label;
			if ( 'Email' === $label ) {
				$values['required'] = 1;
			}
			if ( 'select' === $type ) {
				$values['options'] = array( 'Sales', 'Support' );
			}
			self::$fields[ $label ] = (int) \FrmField::create( $values );
		}
		self::entry( 'Ann' );
		self::entry( 'Bob' );
	}

	/**
	 * An entry saved through Formidable's own model.
	 *
	 * @param string $name Visitor.
	 */
	private static function entry( string $name ): int {
		return (int) \FrmEntry::create(
			array(
				'form_id'   => self::$form,
				'item_key'  => 'kuduy' . strtolower( $name ) . wp_generate_password( 6, false ),
				'item_meta' => array(
					self::$fields['Name']    => $name . ' Lee',
					self::$fields['Email']   => strtolower( $name ) . '@visitor.test',
					self::$fields['Topic']   => 'Sales',
					self::$fields['Message'] => 'Hello from ' . $name,
					self::$fields['PIN']     => self::PIN,
				),
			)
		);
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	public function test_status_list_and_form(): void {
		$plugins = array_column( $this->ok( 'kuduy-forms-status' )['plugins'], null, 'plugin' );
		$this->assertSame( \FrmAppHelper::plugin_version(), $plugins['formidable']['version'] );
		$this->assertSame( 'plugin', $plugins['formidable']['entries_store'] );

		$forms = array_column( $this->ok( 'kuduy-forms-list', array( 'plugin' => 'formidable' ) )['forms'], null, 'id' );
		$this->assertSame( 'Kuduy Formidable contact', $forms[ self::$form ]['title'] );
		$this->assertGreaterThanOrEqual( 2, $forms[ self::$form ]['entries'] );
		$this->assertSame( '[formidable id="' . self::$form . '"]', $forms[ self::$form ]['shortcode'] );

		$form   = $this->ok(
			'kuduy-forms-get',
			array(
				'plugin'  => 'formidable',
				'form_id' => self::$form,
			)
		);
		$fields = array_column( $form['fields'], null, 'label' );
		$this->assertSame( array( 'Name', 'Email', 'Topic', 'Message', 'PIN' ), array_keys( $fields ) );
		$this->assertTrue( $fields['Email']['required'] );
		$this->assertSame( array( 'Sales', 'Support' ), $fields['Topic']['choices'] );
		$this->assertArrayNotHasKey( 'kind', $fields['Email'] );
		$this->assertSame( 'Send', $form['submit_text'] );

		wp_set_current_user( self::$editor );
		$this->assertSame( array(), $this->ok( 'kuduy-forms-list', array( 'plugin' => 'formidable' ) )['forms'], 'No Formidable capability, no forms.' );
		$this->fails(
			'kuduy-forms-get',
			array(
				'plugin'  => 'formidable',
				'form_id' => self::$form,
			),
			'editor'
		);
	}

	public function test_entries_masked_and_passwords_never_shown(): void {
		$args = array(
			'plugin'  => 'formidable',
			'form_id' => self::$form,
		);
		$list = $this->ok( 'kuduy-forms-entries', $args );
		$this->assertGreaterThanOrEqual( 2, $list['total'] );
		$entry  = $list['entries'][0];
		$fields = array_column( $entry['fields'], null, 'label' );
		$this->assertSame( '[personal data]', $fields['Name']['value'] );
		$this->assertSame( '[personal data]', $fields['Email']['value'] );
		$this->assertSame( 'Sales', $fields['Topic']['value'] );
		$this->assertStringStartsWith( 'Hello from', $fields['Message']['value'] );
		$this->assertNull( $fields['PIN']['value'] );
		$this->assertTrue( $fields['PIN']['has_value'] );
		$this->assertStringNotContainsString( self::PIN, (string) wp_json_encode( $list ) );
		$this->assertSame( 0, $this->ok( 'kuduy-forms-entries', $args + array( 'status' => 'trash' ) )['total'], 'Formidable has no trash.' );
		$this->assertSame( 0, $this->ok( 'kuduy-forms-entries', $args + array( 'status' => 'spam' ) )['total'] );
		$this->assertSame( $list['total'], $this->ok( 'kuduy-forms-entries', $args + array( 'status' => 'all' ) )['total'] );
		$one = $this->ok( 'kuduy-forms-entries', $args + array( 'per_page' => 1, 'page' => 2 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- paging.
		$this->assertCount( 1, $one['entries'] );
		$this->assertNotSame( $entry['id'], $one['entries'][0]['id'] );
		$this->fails( 'kuduy-forms-entries', $args + array( 'search' => 'Ann' ), 'search is not supported for Formidable' );

		$full = $this->ok(
			'kuduy-forms-entry-get',
			array(
				'plugin'      => 'formidable',
				'entry_id'    => (int) $entry['id'],
				'include_pii' => true,
			)
		);
		$this->assertStringEndsWith( '@visitor.test', array_column( $full['fields'], 'value', 'label' )['Email'] );
		$this->assertStringNotContainsString( self::PIN, (string) wp_json_encode( $full ), 'Not even with full data.' );

		wp_set_current_user( self::$editor );
		$this->fails( 'kuduy-forms-entries', $args, 'editor' );
	}

	public function test_entries_are_read_only(): void {
		$id      = self::entry( 'Cyd' );
		$args    = array(
			'plugin'   => 'formidable',
			'entry_id' => $id,
		);
		$message = $this->fails( 'kuduy-forms-entry-update', $args + array( 'status' => 'trash' ), 'no trash in Formidable' );
		$this->assertStringContainsString( 'read only', $message );
		$this->fails( 'kuduy-forms-entry-update', $args + array( 'read' => true ), 'no read flag' );
		$this->fails( 'kuduy-forms-entry-note', $args + array( 'note' => 'x' ), 'no notes' );
		$this->assertSame( 'active', $this->ok( 'kuduy-forms-entry-get', $args )['status'], 'The entry is still there.' );
		$this->fails(
			'kuduy-forms-entry-get',
			array(
				'plugin'   => 'formidable',
				'entry_id' => 99999999,
			),
			'unknown entry'
		);
	}
}
