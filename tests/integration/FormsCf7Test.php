<?php
/**
 * Phase 7 forms, Contact Form 7 + Flamingo (test matrix F1–F4, F6, F7; decisions D15–D17 A): status, forms the user
 * may read, form details through CF7's own API, integration keys never returned, Flamingo entries masked by field
 * kind, inbox actions with undo, Akismet reporting confirm-first, CF7 form saves and mail routing confirm-first.
 * Runs only when Contact Form 7 is active (KUDUY_PLUGINS=contact-form-7@…,flamingo@…).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Mcp\ToolRegistry;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';
require_once __DIR__ . '/KuduyFakeAkismet.php';

final class FormsCf7Test extends TestCase {

	use CallsTools;

	private const RECAPTCHA_SECRET = 'cf7-recaptcha-secret-4711';

	private static int $admin;
	private static int $editor;
	private static int $author;
	private static int $subscriber;
	private static int $contact;
	private static int $quote;

	public static function setUpBeforeClass(): void {
		if ( ! function_exists( 'wpcf7_save_contact_form' ) ) {
			self::markTestSkipped( 'Contact Form 7 is not active in this run (KUDUY_PLUGINS).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin      = self::make_user( 'administrator' );
		self::$editor     = self::make_user( 'editor' );
		self::$author     = self::make_user( 'author' );
		self::$subscriber = self::make_user( 'subscriber' );
		wp_set_current_user( self::$admin );

		self::$contact = self::cf7_form(
			'Kuduy contact',
			"<label>Name [text* your-name]</label>\n<label>Email [email* your-email]</label>\n[tel your-phone]\n[select topic \"Sales\" \"Support\"]\n[textarea your-message]\n[submit \"Send\"]",
			'office@example.test'
		);
		self::$quote   = self::cf7_form( 'Kuduy quote', "[text company]\n[submit]", 'sales@example.test' );

		// CF7 integration keys (reCAPTCHA): stored in option wpcf7, never returned.
		$option              = (array) get_option( 'wpcf7', array() );
		$option['recaptcha'] = array( 'kuduy-site-key' => self::RECAPTCHA_SECRET );
		update_option( 'wpcf7', $option );

		if ( class_exists( 'Flamingo_Inbound_Message' ) ) {
			self::message( 'Ann' );
			self::message( 'Bob' );
		}
	}

	/**
	 * A CF7 form saved through CF7's own save function.
	 *
	 * @param string $title     Title.
	 * @param string $markup    Form-tag markup.
	 * @param string $recipient Mail recipient.
	 */
	private static function cf7_form( string $title, string $markup, string $recipient ): int {
		$form = wpcf7_save_contact_form(
			array(
				'title' => $title,
				'form'  => $markup,
				'mail'  => array(
					'subject'            => 'New message',
					'sender'             => 'Site <wordpress@example.test>',
					'recipient'          => $recipient,
					'body'               => '[your-message]',
					'additional_headers' => 'Reply-To: [your-email]',
				),
			)
		);
		return $form ? (int) $form->id() : 0;
	}

	/**
	 * A Flamingo message on the contact form, saved the way CF7's Flamingo module saves one.
	 *
	 * @param string               $name    Visitor name.
	 * @param array<string, mixed> $akismet Akismet details.
	 */
	private static function message( string $name, array $akismet = array() ): int {
		$form    = wpcf7_contact_form( self::$contact );
		$channel = get_term( (int) wpcf7_flamingo_add_channel( $form->name(), $form->title() ) );
		$email   = strtolower( $name ) . '@visitor.test';
		return (int) \Flamingo_Inbound_Message::add(
			array(
				'channel'    => $channel instanceof \WP_Term ? $channel->slug : 'contact-form-7',
				'subject'    => 'Hello from ' . $name,
				'from'       => $name . ' <' . $email . '>',
				'from_name'  => $name,
				'from_email' => $email,
				'fields'     => array(
					'your-name'    => $name,
					'your-email'   => $email,
					'your-phone'   => '+1 555 0100',
					'topic'        => 'Sales',
					'your-message' => 'Need a quote from ' . $name,
				),
				'meta'       => array(
					'remote_ip'  => '203.0.113.7',
					'user_agent' => 'KuduyBrowser/1.0',
					'url'        => 'https://example.test/contact/',
				),
				'akismet'    => $akismet,
			)
		)->id();
	}

	protected function setUp(): void {
		wp_set_current_user( self::$editor );
		\Akismet::$key   = '';
		\Akismet::$calls = array();
	}

	/**
	 * Skip when Flamingo is not part of this run.
	 */
	private function needs_flamingo(): void {
		if ( ! class_exists( 'Flamingo_Inbound_Message' ) ) {
			$this->markTestSkipped( 'Flamingo is not active in this run.' );
		}
	}

	public function test_status_names_cf7_and_where_entries_are_stored(): void {
		$plugins = array_column( $this->ok( 'kuduy-forms-status' )['plugins'], null, 'plugin' );
		$this->assertSame( WPCF7_VERSION, $plugins['cf7']['version'] );
		$this->assertSame( class_exists( 'Flamingo_Inbound_Message' ) ? 'flamingo' : null, $plugins['cf7']['entries_store'] );
		$this->assertNull( $plugins['cf7']['abilities'], 'CF7 registers no abilities.' );
	}

	public function test_list_shows_forms_the_user_may_read(): void {
		$forms = array_column(
			array_filter( $this->ok( 'kuduy-forms-list' )['forms'], static fn( $f ) => 'cf7' === $f['plugin'] ),
			null,
			'id'
		);
		$this->assertSame( 'Kuduy contact', $forms[ self::$contact ]['title'] );
		$this->assertStringContainsString( 'contact-form-7', $forms[ self::$contact ]['shortcode'] );
		$this->assertArrayHasKey( self::$quote, $forms );
		// Flamingo messages are admin-only by default (edit_users): an editor sees no count.
		$this->assertNull( $forms[ self::$contact ]['entries'] );

		if ( class_exists( 'Flamingo_Inbound_Message' ) ) {
			wp_set_current_user( self::$admin );
			$forms = array_column( $this->ok( 'kuduy-forms-list', array( 'plugin' => 'cf7' ) )['forms'], null, 'id' );
			$this->assertGreaterThanOrEqual( 2, $forms[ self::$contact ]['entries'] );
			$this->assertSame( 0, $forms[ self::$quote ]['entries'] );
		}

		wp_set_current_user( self::$subscriber );
		$this->assertSame( array(), $this->ok( 'kuduy-forms-list', array( 'plugin' => 'cf7' ) )['forms'], 'CF7 needs edit_posts to read forms.' );
	}

	public function test_get_returns_fields_messages_and_mail_routing(): void {
		$form   = $this->ok(
			'kuduy-forms-get',
			array(
				'plugin'  => 'cf7',
				'form_id' => self::$contact,
			)
		);
		$fields = array_column( $form['fields'], null, 'name' );
		$this->assertSame( array( 'your-name', 'your-email', 'your-phone', 'topic', 'your-message' ), array_keys( $fields ), 'The submit button is not a field.' );
		$this->assertTrue( $fields['your-name']['required'] );
		$this->assertFalse( $fields['your-phone']['required'] );
		$this->assertSame( 'email', $fields['your-email']['type'] );
		$this->assertSame( array( 'Sales', 'Support' ), $fields['topic']['choices'] );
		$mail = array_column( $form['notifications'], null, 'name' );
		$this->assertSame( 'office@example.test', $mail['mail']['recipient'] );
		$this->assertTrue( $mail['mail']['active'] );
		$this->assertSame( 'Reply-To: [your-email]', $mail['mail']['additional_headers'] );
		$this->assertNotEmpty( $form['messages']['mail_sent_ok'] ?? '' );
		$this->assertStringNotContainsString( self::RECAPTCHA_SECRET, (string) wp_json_encode( $form ) );
		$this->assertStringNotContainsString( self::RECAPTCHA_SECRET, (string) wp_json_encode( $this->ok( 'kuduy-forms-status' ) ) );
	}

	public function test_get_refusals(): void {
		$this->fails(
			'kuduy-forms-get',
			array(
				'plugin'  => 'cf7',
				'form_id' => 999999,
			),
			'unknown form'
		);
		$this->fails(
			'kuduy-forms-get',
			array(
				'plugin'  => 'cf7',
				'form_id' => (int) wp_insert_post( array( 'post_title' => 'Not a form' ) ),
			),
			'a post that is not a CF7 form'
		);
		$this->fails(
			'kuduy-forms-get',
			array(
				'plugin'  => 'nope',
				'form_id' => self::$contact,
			),
			'unknown plugin'
		);
		wp_set_current_user( self::$subscriber );
		$this->fails(
			'kuduy-forms-get',
			array(
				'plugin'  => 'cf7',
				'form_id' => self::$contact,
			),
			'subscriber cannot read CF7 forms'
		);
	}

	public function test_entries_are_masked_by_field_kind(): void {
		$this->needs_flamingo();
		wp_set_current_user( self::$admin );
		$list = $this->ok(
			'kuduy-forms-entries',
			array(
				'plugin'  => 'cf7',
				'form_id' => self::$contact,
			)
		);
		$this->assertGreaterThanOrEqual( 2, $list['total'] );
		$entry  = $list['entries'][0];
		$fields = array_column( $entry['fields'], 'value', 'name' );
		$this->assertSame( '[personal data]', $fields['your-email'] );
		$this->assertSame( '[personal data]', $fields['your-name'], 'Name fields are masked by name.' );
		$this->assertSame( '[personal data]', $fields['your-phone'] );
		$this->assertSame( 'Sales', $fields['topic'] );
		$this->assertStringStartsWith( 'Need a quote from', $fields['your-message'], 'Message text is shown.' );
		$this->assertSame( '[personal data]', $entry['meta']['ip'] );
		$this->assertSame( '[personal data]', $entry['sender']['email'] );
		$this->assertSame( 'https://example.test/contact/', $entry['meta']['source_url'] );
		$this->assertSame( 'masked', $entry['personal_data'] );
		$this->assertStringNotContainsString( '@visitor.test', (string) wp_json_encode( $list ) );

		$full   = $this->ok(
			'kuduy-forms-entries',
			array(
				'plugin'      => 'cf7',
				'form_id'     => self::$contact,
				'include_pii' => true,
			)
		);
		$values = array_column( $full['entries'][0]['fields'], 'value', 'name' );
		$this->assertStringEndsWith( '@visitor.test', $values['your-email'] );
		$this->assertSame( '203.0.113.7', $full['entries'][0]['meta']['ip'] );

		wp_set_current_user( self::$editor );
		$this->fails(
			'kuduy-forms-entries',
			array(
				'plugin'  => 'cf7',
				'form_id' => self::$contact,
			),
			'Flamingo messages are for users who can edit users'
		);
	}

	public function test_entry_get_names_the_form(): void {
		$this->needs_flamingo();
		wp_set_current_user( self::$admin );
		$id    = self::message( 'Cyd' );
		$entry = $this->ok(
			'kuduy-forms-entry-get',
			array(
				'plugin'   => 'cf7',
				'entry_id' => $id,
			)
		);
		$this->assertSame( $id, $entry['id'] );
		$this->assertSame( self::$contact, $entry['form_id'] );
		$this->assertSame( 'active', $entry['status'] );
		$this->assertNull( $entry['read'] );
		$this->assertSame( array(), $entry['notes'] );
		$this->fails(
			'kuduy-forms-entry-get',
			array(
				'plugin'   => 'cf7',
				'entry_id' => self::$contact,
			),
			'a form id is not a message id'
		);
	}

	public function test_trash_and_spam_with_undo(): void {
		$this->needs_flamingo();
		wp_set_current_user( self::$admin );
		$id      = self::message( 'Dee' );
		$trashed = $this->ok(
			'kuduy-forms-entry-update',
			array(
				'plugin'   => 'cf7',
				'entry_id' => $id,
				'status'   => 'trash',
			)
		);
		$this->assertTrue( $trashed['done'] );
		$this->assertSame( 'trash', get_post_status( $id ) );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $trashed['_snapshot_id'] ) );
		$this->assertSame( 'publish', get_post_status( $id ) );

		$spam = $this->ok(
			'kuduy-forms-entry-update',
			array(
				'plugin'   => 'cf7',
				'entry_id' => $id,
				'status'   => 'spam',
			)
		);
		$this->assertTrue( $spam['done'], 'No Akismet: no confirmation needed.' );
		$this->assertSame( 'flamingo-spam', get_post_status( $id ) );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $spam['_snapshot_id'] ) );
		$this->assertSame( 'publish', get_post_status( $id ) );

		$this->fails(
			'kuduy-forms-entry-update',
			array(
				'plugin'   => 'cf7',
				'entry_id' => $id,
				'read'     => true,
			),
			'Flamingo has no read flag'
		);
		$this->fails(
			'kuduy-forms-entry-update',
			array(
				'plugin'   => 'cf7',
				'entry_id' => $id,
			),
			'nothing asked for'
		);
		$this->fails(
			'kuduy-forms-entry-note',
			array(
				'plugin'   => 'cf7',
				'entry_id' => $id,
				'note'     => 'Called back',
			),
			'Flamingo has no notes'
		);
		wp_set_current_user( self::$editor );
		$this->fails(
			'kuduy-forms-entry-update',
			array(
				'plugin'   => 'cf7',
				'entry_id' => $id,
				'status'   => 'trash',
			),
			'editor cannot trash Flamingo messages'
		);
		$this->assertSame( 'publish', get_post_field( 'post_status', $id ), 'The refused call changed nothing.' );
	}

	public function test_akismet_report_is_confirm_first_and_undo_reports_nothing(): void {
		$this->needs_flamingo();
		wp_set_current_user( self::$admin );
		\Akismet::$key = 'kuduy-test-key';
		$id            = self::message( 'Eve', array( 'comment' => array( 'comment_content' => 'Need a quote' ) ) );
		$args          = array(
			'plugin'   => 'cf7',
			'entry_id' => $id,
			'status'   => 'spam',
		);
		$first         = $this->ok( 'kuduy-forms-entry-update', $args );
		$this->assertFalse( $first['done'] );
		$this->assertStringContainsString( 'Akismet', implode( ' ', $first['impact'] ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertSame( array(), \Akismet::$calls );
		$this->assertArrayNotHasKey( '_snapshot_id', $first );

		$done = $this->ok( 'kuduy-forms-entry-update', $args + array( 'confirm' => true ) );
		$this->assertTrue( $done['done'] );
		$this->assertSame( 'flamingo-spam', get_post_status( $id ) );
		$this->assertSame( array( 'submit-spam' ), \Akismet::$calls );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $done['_snapshot_id'] ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertSame( array( 'submit-spam' ), \Akismet::$calls, 'Undo sends nothing to Akismet.' );
		$this->assertSame( array( 'comment_content' => 'Need a quote' ), get_post_meta( $id, '_akismet', true )['comment'] ?? null );
	}

	public function test_cf7_save_creates_patches_and_undoes(): void {
		wp_set_current_user( self::$admin );
		$created = $this->ok(
			'kuduy-forms-cf7-save',
			array(
				'title' => 'Kuduy newsletter',
				'form'  => "[email* subscriber-email]\n[submit \"Join\"]",
			)
		);
		$this->assertTrue( $created['created'] );
		$fields = array_column(
			$this->ok(
				'kuduy-forms-get',
				array(
					'plugin'  => 'cf7',
					'form_id' => $created['id'],
				)
			)['fields'],
			'type',
			'name'
		);
		$this->assertSame( array( 'subscriber-email' => 'email' ), $fields );

		$patched = $this->ok(
			'kuduy-forms-cf7-save',
			array(
				'form_id'  => $created['id'],
				'title'    => 'Kuduy newsletter (new)',
				'messages' => array( 'mail_sent_ok' => 'Thanks for joining.' ),
			)
		);
		$form    = wpcf7_contact_form( $created['id'] );
		$this->assertSame( 'Kuduy newsletter (new)', $form->title() );
		$this->assertSame( 'Thanks for joining.', $form->prop( 'messages' )['mail_sent_ok'] );
		$this->assertNotEmpty( $form->prop( 'messages' )['mail_sent_ng'], 'Other messages are kept.' );
		$this->assertStringContainsString( 'subscriber-email', $form->prop( 'form' ), 'Markup left out stays.' );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $patched['_snapshot_id'] ) );
		$form = wpcf7_contact_form( $created['id'] );
		$this->assertSame( 'Kuduy newsletter', $form->title() );
		$this->assertNotSame( 'Thanks for joining.', $form->prop( 'messages' )['mail_sent_ok'] );

		wp_set_current_user( self::$author );
		$this->fails(
			'kuduy-forms-cf7-save',
			array(
				'form_id' => $created['id'],
				'title'   => 'Author edit',
			),
			'authors cannot edit CF7 forms (publish_pages)'
		);
	}

	public function test_cf7_markup_is_filtered_without_unfiltered_html(): void {
		wp_set_current_user( self::$editor );
		$deny = static fn( $caps, $cap ) => 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
		add_filter( 'map_meta_cap', $deny, 10, 2 );
		try {
			$saved = $this->ok(
				'kuduy-forms-cf7-save',
				array(
					'title' => 'Kuduy filtered',
					'form'  => "<script>alert(1)</script>[text your-name]\n[submit]",
				)
			);
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
		}
		$this->assertStringNotContainsString( '<script>', (string) wpcf7_contact_form( $saved['id'] )->prop( 'form' ) );
	}

	public function test_mail_routing_is_confirm_first_and_needs_admin_scope(): void {
		wp_set_current_user( self::$admin );
		$this->assertSame( 'kuduy:admin', ToolRegistry::create()->get( 'kuduy-forms-cf7-mail' )->scope() );
		$this->assertSame( 'kuduy:write', ToolRegistry::create()->get( 'kuduy-forms-cf7-save' )->scope() );
		$args  = array(
			'form_id' => self::$quote,
			'mail'    => array( 'recipient' => 'leak@elsewhere.test' ),
		);
		$first = $this->ok( 'kuduy-forms-cf7-mail', $args );
		$this->assertFalse( $first['done'] );
		$this->assertStringContainsString( 'sales@example.test', implode( ' ', $first['impact'] ) );
		$this->assertStringContainsString( 'leak@elsewhere.test', implode( ' ', $first['impact'] ) );
		$this->assertSame( 'sales@example.test', wpcf7_contact_form( self::$quote )->prop( 'mail' )['recipient'] );

		$done = $this->ok( 'kuduy-forms-cf7-mail', $args + array( 'confirm' => true ) );
		$this->assertTrue( $done['done'] );
		$this->assertSame( 'leak@elsewhere.test', wpcf7_contact_form( self::$quote )->prop( 'mail' )['recipient'] );
		$this->assertSame( 'New message', wpcf7_contact_form( self::$quote )->prop( 'mail' )['subject'], 'Mail keys left out stay.' );
		$this->assertStringNotContainsString( 'leak@elsewhere.test', (string) wp_json_encode( AuditLog::latest( 5 ) ), 'Mail addresses stay out of the audit log.' );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $done['_snapshot_id'] ) );
		$this->assertSame( 'sales@example.test', wpcf7_contact_form( self::$quote )->prop( 'mail' )['recipient'] );

		$subject = $this->ok(
			'kuduy-forms-cf7-mail',
			array(
				'form_id' => self::$quote,
				'mail'    => array( 'subject' => 'Quote request' ),
			)
		);
		$this->assertTrue( $subject['done'], 'A subject change does not move mail elsewhere.' );
		$this->assertSame( array(), $subject['impact'] );
		$this->assertSame( 'Quote request', wpcf7_contact_form( self::$quote )->prop( 'mail' )['subject'] );

		$second = $this->ok(
			'kuduy-forms-cf7-mail',
			array(
				'form_id' => self::$quote,
				'mail_2'  => array(
					'active'    => true,
					'recipient' => '[your-email]',
				),
			)
		);
		$this->assertFalse( $second['done'], 'Switching on the second mail is a routing change.' );
	}
}
