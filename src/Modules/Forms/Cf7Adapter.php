<?php
/**
 * Contact Form 7 (+ Flamingo for entries) through their own classes.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Forms;

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Forms: WPCF7_ContactForm (post type wpcf7_contact_form), permissions from CF7's meta capabilities
 * (wpcf7_read_contact_form, filterable through wpcf7_map_meta_cap). Entries: Flamingo_Inbound_Message (post type
 * flamingo_inbound), permissions from Flamingo's meta capabilities (all edit_users by default). Option wpcf7
 * (reCAPTCHA, Turnstile, Stripe, Brevo keys) is never read.
 */
final class Cf7Adapter implements FormsAdapter {

	/**
	 * Form-tag types whose values are a list of choices.
	 */
	private const CHOICE_TYPES = array( 'select', 'checkbox', 'radio' );

	/**
	 * Post status per entry status.
	 */
	private const POST_STATUS = array(
		'active' => 'publish',
		'spam'   => 'flamingo-spam',
		'trash'  => 'trash',
	);

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'cf7';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'Contact Form 7';
	}

	/**
	 * {@inheritDoc}
	 */
	public function active(): bool {
		return class_exists( 'WPCF7_ContactForm' ) && function_exists( 'wpcf7_contact_form' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function version(): string {
		return defined( 'WPCF7_VERSION' ) ? (string) WPCF7_VERSION : '';
	}

	/**
	 * Whether Flamingo (CF7's entry store) is loaded.
	 */
	private static function flamingo(): bool {
		return class_exists( 'Flamingo_Inbound_Message' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function entries_store(): ?string {
		return self::flamingo() ? 'flamingo' : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function abilities(): ?bool {
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function forms(): array {
		$out = array();
		foreach ( \WPCF7_ContactForm::find() as $form ) {
			if ( current_user_can( 'wpcf7_read_contact_form', $form->id() ) ) {
				$out[] = $this->summary( $form );
			}
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Form id.
	 * @throws ToolError When the form is missing or the user may not read it.
	 */
	public function form( int $id ): array {
		$form = self::find( $id );
		if ( ! current_user_can( 'wpcf7_read_contact_form', $id ) ) {
			throw new ToolError( 'Contact Form 7 does not allow your account to read this form.' );
		}
		$fields = array();
		foreach ( $form->scan_form_tags() as $tag ) {
			if ( '' === (string) $tag->name ) {
				// Submit buttons, response output and other tags without a value.
				continue;
			}
			$field = array(
				'name'     => (string) $tag->name,
				'label'    => (string) $tag->name,
				'type'     => (string) $tag->basetype,
				'required' => (bool) $tag->is_required(),
			);
			if ( in_array( $tag->basetype, self::CHOICE_TYPES, true ) ) {
				$field['choices'] = array_values( array_map( 'strval', (array) $tag->values ) );
			}
			$fields[] = $field;
		}
		return $this->summary( $form ) + array(
			'locale'              => (string) $form->locale(),
			'fields'              => $fields,
			'messages'            => (object) array_map( 'strval', (array) $form->prop( 'messages' ) ),
			'notifications'       => self::mails( $form ),
			'additional_settings' => (string) $form->prop( 'additional_settings' ),
		);
	}

	/**
	 * The two CF7 mails of a form (where submissions are emailed).
	 *
	 * @param \WPCF7_ContactForm $form Form.
	 * @return list<array<string, mixed>>
	 */
	public static function mails( \WPCF7_ContactForm $form ): array {
		$out = array();
		foreach ( array( 'mail', 'mail_2' ) as $name ) {
			$mail  = (array) $form->prop( $name );
			$out[] = array(
				'name'               => $name,
				'active'             => 'mail' === $name ? true : ! empty( $mail['active'] ),
				'recipient'          => (string) ( $mail['recipient'] ?? '' ),
				'sender'             => (string) ( $mail['sender'] ?? '' ),
				'subject'            => (string) ( $mail['subject'] ?? '' ),
				'body'               => (string) ( $mail['body'] ?? '' ),
				'additional_headers' => (string) ( $mail['additional_headers'] ?? '' ),
				'attachments'        => (string) ( $mail['attachments'] ?? '' ),
				'use_html'           => ! empty( $mail['use_html'] ),
			);
		}
		return $out;
	}

	/**
	 * A CF7 form by id, or a ToolError.
	 *
	 * @param int $id Form id.
	 * @throws ToolError When there is none.
	 */
	public static function find( int $id ): \WPCF7_ContactForm {
		$post = $id > 0 ? get_post( $id ) : null;
		$form = $post && \WPCF7_ContactForm::post_type === $post->post_type ? wpcf7_contact_form( $post ) : null;
		if ( ! $form instanceof \WPCF7_ContactForm ) {
			throw new ToolError( 'No Contact Form 7 form with id ' . (int) $id . '.' );
		}
		return $form;
	}

	/**
	 * Summary row.
	 *
	 * @param \WPCF7_ContactForm $form Form.
	 * @return array<string, mixed>
	 */
	private function summary( \WPCF7_ContactForm $form ): array {
		return array(
			'plugin'    => $this->slug(),
			'id'        => (int) $form->id(),
			'title'     => (string) $form->title(),
			'entries'   => $this->count( $form ),
			'shortcode' => (string) $form->shortcode(),
		);
	}

	/**
	 * Number of Flamingo messages of a form (not spam or trash), or null when Flamingo is absent or the user may not
	 * read messages.
	 *
	 * @param \WPCF7_ContactForm $form Form.
	 */
	private function count( \WPCF7_ContactForm $form ): ?int {
		if ( ! self::flamingo() || ! current_user_can( 'flamingo_edit_inbound_messages' ) ) {
			return null;
		}
		$channel = self::channel( $form );
		return $channel > 0 ? (int) \Flamingo_Inbound_Message::count( array( 'channel_id' => $channel ) ) : 0;
	}

	/**
	 * Flamingo channel (term id) of a form: CF7 stores it in the form's _flamingo meta after the first message, else
	 * the channel is the form's slug under the "contact-form-7" parent (wpcf7_flamingo_add_channel()).
	 *
	 * @param \WPCF7_ContactForm $form Form.
	 */
	private static function channel( \WPCF7_ContactForm $form ): int {
		$meta = get_post_meta( (int) $form->id(), '_flamingo', true );
		if ( is_array( $meta ) && ! empty( $meta['channel'] ) ) {
			return (int) $meta['channel'];
		}
		$taxonomy = \Flamingo_Inbound_Message::channel_taxonomy;
		$parent   = term_exists( 'contact-form-7', $taxonomy );
		if ( ! is_array( $parent ) ) {
			return 0;
		}
		$term = term_exists( (string) $form->name(), $taxonomy, (int) $parent['term_id'] );
		return is_array( $term ) ? (int) $term['term_id'] : 0;
	}

	/**
	 * Flamingo must be active for entries.
	 *
	 * @throws ToolError When it is not.
	 */
	private function assert_flamingo(): void {
		if ( ! self::flamingo() ) {
			Forms::no_entries( $this, 'Contact Form 7 only emails submissions. Install and activate Flamingo (by the author of Contact Form 7) to store them.' );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $form_id Form id.
	 * @param array<string, mixed> $query   Query.
	 * @throws ToolError When Flamingo is absent, the form is missing or the user may not read messages.
	 */
	public function entries( int $form_id, array $query ): array {
		$this->assert_flamingo();
		$form = self::find( $form_id );
		if ( ! current_user_can( 'flamingo_edit_inbound_messages' ) ) {
			throw new ToolError( 'Flamingo does not allow your account to read messages.' );
		}
		$channel = self::channel( $form );
		if ( $channel <= 0 ) {
			return array(
				'entries' => array(),
				'total'   => 0,
			);
		}
		[ $page, $per ] = Forms::paging( $query );
		$status         = (string) ( $query['status'] ?? 'active' );
		$args           = array(
			'channel_id'     => $channel,
			'posts_per_page' => $per,
			'offset'         => ( $page - 1 ) * $per,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'post_status'    => 'all' === $status ? array_values( self::POST_STATUS ) : ( self::POST_STATUS[ $status ] ?? 'publish' ),
		);
		if ( '' !== (string) ( $query['search'] ?? '' ) ) {
			$args['s'] = (string) $query['search'];
		}
		$kinds   = self::kinds( $form );
		$entries = array();
		foreach ( \Flamingo_Inbound_Message::find( $args ) as $message ) {
			$entries[] = self::normalize( $message, (int) $form->id(), $kinds );
		}
		return array(
			'entries' => $entries,
			'total'   => (int) \Flamingo_Inbound_Message::count(),
		);
	}

	/**
	 * A Flamingo message by id, or a ToolError.
	 *
	 * @param int $id Message (post) id.
	 * @throws ToolError When there is none.
	 */
	private function message( int $id ): \Flamingo_Inbound_Message {
		$this->assert_flamingo();
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post || \Flamingo_Inbound_Message::post_type !== $post->post_type ) {
			throw new ToolError( 'No Flamingo message with id ' . (int) $id . '.' );
		}
		return new \Flamingo_Inbound_Message( $post );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $entry_id Entry id.
	 * @throws ToolError When the message is missing or the user may not read it.
	 */
	public function entry( int $entry_id ): array {
		$message = $this->message( $entry_id );
		if ( ! $this->allowed( 'read', $entry_id ) ) {
			throw new ToolError( 'Flamingo does not allow your account to read this message.' );
		}
		$form  = self::form_of( $entry_id );
		$entry = self::normalize( $message, $form ? (int) $form->id() : 0, $form ? self::kinds( $form ) : array() );
		return $entry + array( 'notes' => array() );
	}

	/**
	 * The CF7 form a message came from (by Flamingo channel), or null.
	 *
	 * @param int $entry_id Message id.
	 */
	private static function form_of( int $entry_id ): ?\WPCF7_ContactForm {
		$terms = wp_get_object_terms( $entry_id, \Flamingo_Inbound_Message::channel_taxonomy, array( 'fields' => 'ids' ) );
		if ( ! is_array( $terms ) || ! $terms ) {
			return null;
		}
		foreach ( \WPCF7_ContactForm::find() as $form ) {
			if ( in_array( self::channel( $form ), array_map( 'intval', $terms ), true ) ) {
				return $form;
			}
		}
		return null;
	}

	/**
	 * Field kinds of a form by field name, from its form-tag types.
	 *
	 * @param \WPCF7_ContactForm $form Form.
	 * @return array<string, string>
	 */
	private static function kinds( \WPCF7_ContactForm $form ): array {
		$map   = array(
			'email' => 'email',
			'tel'   => 'phone',
			'file'  => 'file',
		);
		$kinds = array();
		foreach ( $form->scan_form_tags() as $tag ) {
			$name = (string) $tag->name;
			if ( '' !== $name ) {
				$kinds[ $name ] = $map[ (string) $tag->basetype ] ?? Mask::kind_from_name( $name );
			}
		}
		return $kinds;
	}

	/**
	 * One message in the shared entry shape.
	 *
	 * @param \Flamingo_Inbound_Message $message Message.
	 * @param int                       $form_id Form id (0 when unknown).
	 * @param array<string, string>     $kinds   Field kinds by name.
	 * @return array<string, mixed>
	 */
	private static function normalize( \Flamingo_Inbound_Message $message, int $form_id, array $kinds ): array {
		$id     = (int) $message->id();
		$status = (string) get_post_status( $id );
		$fields = array();
		foreach ( (array) $message->fields as $name => $value ) {
			$name     = (string) $name;
			$fields[] = array(
				'name'  => $name,
				'label' => $name,
				'kind'  => $kinds[ $name ] ?? Mask::kind_from_name( $name ),
				'value' => $value,
			);
		}
		$meta = (array) $message->meta;
		return array(
			'plugin'  => 'cf7',
			'id'      => $id,
			'form_id' => $form_id,
			'date'    => (string) get_post_field( 'post_date', $id ),
			'status'  => (string) ( array_search( $status, self::POST_STATUS, true ) ?: 'active' ), // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- false when unknown.
			'read'    => null,
			'starred' => null,
			'subject' => (string) $message->subject,
			'sender'  => array(
				'name'  => (string) $message->from_name,
				'email' => (string) $message->from_email,
			),
			'fields'  => $fields,
			'meta'    => array(
				'ip'         => (string) ( $meta['remote_ip'] ?? '' ),
				'user_agent' => (string) ( $meta['user_agent'] ?? '' ),
				'source_url' => (string) ( $meta['url'] ?? '' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $action   read | edit | trash | spam | note.
	 * @param int    $entry_id Entry id.
	 */
	public function allowed( string $action, int $entry_id ): bool {
		return match ( $action ) {
			'read', 'edit' => current_user_can( 'flamingo_edit_inbound_message', $entry_id ),
			'trash'        => current_user_can( 'flamingo_delete_inbound_message', $entry_id ),
			'spam'         => current_user_can( 'flamingo_spam_inbound_message', $entry_id ) && current_user_can( 'flamingo_unspam_inbound_message', $entry_id ),
			default        => false,
		};
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $entry_id Entry id.
	 */
	public function state( int $entry_id ): array {
		$this->message( $entry_id );
		$status = (string) get_post_status( $entry_id );
		return array(
			'status'   => (string) ( array_search( $status, self::POST_STATUS, true ) ?: 'active' ), // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- false when unknown.
			'read'     => null,
			'starred'  => null,
			'akismet'  => get_post_meta( $entry_id, '_akismet', true ),
			'spam_log' => get_post_meta( $entry_id, '_spam_log', true ),
		);
	}

	/**
	 * Whether Flamingo would report this message to Akismet on a spam change.
	 *
	 * @param \Flamingo_Inbound_Message $message Message.
	 */
	private static function reports_to_akismet( \Flamingo_Inbound_Message $message ): bool {
		return function_exists( 'flamingo_akismet_is_active' ) && flamingo_akismet_is_active() && ! empty( $message->akismet['comment'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $entry_id Entry id.
	 * @param array<string, mixed> $change   Change.
	 */
	public function external_effects( int $entry_id, array $change ): array {
		$message = $this->message( $entry_id );
		$from    = $this->state( $entry_id )['status'];
		$to      = (string) ( $change['status'] ?? $from );
		if ( $from === $to || ! in_array( 'spam', array( $from, $to ), true ) || ! self::reports_to_akismet( $message ) ) {
			return array();
		}
		return array( 'Flamingo reports this message to Akismet as ' . ( 'spam' === $to ? 'spam' : 'not spam' ) . ': the message text is sent to Akismet\'s servers.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $entry_id Entry id.
	 * @param array<string, mixed> $change   Change or saved state.
	 * @param bool                 $restore  Undo.
	 * @throws ToolError When the change is not possible.
	 */
	public function change( int $entry_id, array $change, bool $restore = false ): void {
		if ( ! $restore && ( isset( $change['read'] ) || isset( $change['starred'] ) ) ) {
			throw new ToolError( 'Flamingo messages have no read or starred flag; only status (active, spam, trash) can change.' );
		}
		$to = (string) ( $change['status'] ?? '' );
		if ( ! isset( self::POST_STATUS[ $to ] ) ) {
			return;
		}
		$from = $this->state( $entry_id )['status'];
		if ( $from === $to ) {
			return;
		}
		if ( 'trash' === $to ) {
			if ( ! EMPTY_TRASH_DAYS ) {
				throw new ToolError( 'The trash is switched off on this site (EMPTY_TRASH_DAYS is 0), so Flamingo would delete the message permanently. Nothing was changed.' );
			}
			$this->message( $entry_id )->trash();
			return;
		}
		if ( 'trash' === $from ) {
			// Flamingo restores the status the message had before.
			$this->message( $entry_id )->untrash();
			if ( $this->state( $entry_id )['status'] === $to ) {
				return;
			}
		}
		$message = $this->message( $entry_id );
		if ( $restore ) {
			// Undo never reports to Akismet; the saved details are put back below.
			$message->akismet = array();
		}
		if ( 'spam' === $to ) {
			$message->spam();
		} else {
			$message->unspam();
		}
		if ( $restore ) {
			update_post_meta( $entry_id, '_akismet', $change['akismet'] ?? array() );
			update_post_meta( $entry_id, '_spam_log', $change['spam_log'] ?? array() );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $entry_id Entry id.
	 * @param string $text     Note.
	 * @throws ToolError Always: Flamingo has no notes.
	 */
	public function add_note( int $entry_id, string $text ): int {
		throw new ToolError( 'Flamingo messages have no notes.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $entry_id Entry id.
	 * @param int $note_id  Note id.
	 */
	public function delete_note( int $entry_id, int $note_id ): void {
		// Flamingo has no notes, so there is nothing to remove.
	}
}
