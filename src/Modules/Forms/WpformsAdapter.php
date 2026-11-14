<?php
/**
 * WPForms (Lite) forms through its own form handler.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Forms;

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Forms: post type wpforms, read with wpforms()->obj( 'form' ) and wpforms_decode(); permission
 * wpforms_current_user_can( 'view_form_single', $id ) (manage_options in Lite, per-form access control in Pro).
 * WPForms Lite stores no entries on the site. WPForms Pro entries go through Pro's own handlers wpforms()->obj( 'entry' )
 * (get, get_entries, update) and obj( 'entry_meta' ) (notes), with the per-form entry permissions
 * view_entries_form_single, edit_entries_form_single and delete_entries_form_single (D28 A). The handler calls are
 * taken from Lite's own calls into Pro; Pro's column names (viewed, starred, status, fields) are UNVERIFIED against Pro.
 * Only known settings are returned (no provider or payment settings).
 */
final class WpformsAdapter implements FormsAdapter {

	/**
	 * Field types whose values are a list of choices.
	 */
	private const CHOICE_TYPES = array( 'select', 'checkbox', 'radio', 'payment-select', 'payment-multiple', 'payment-checkbox' );

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'wpforms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'WPForms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function active(): bool {
		return function_exists( 'wpforms' ) && function_exists( 'wpforms_decode' ) && function_exists( 'wpforms_current_user_can' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function version(): string {
		return defined( 'WPFORMS_VERSION' ) ? (string) WPFORMS_VERSION : '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function entries_store(): ?string {
		return null !== self::obj( 'entry' ) ? 'plugin' : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function abilities(): bool {
		// Read abilities are always registered on WordPress 6.9+.
		return function_exists( 'wp_register_ability' );
	}

	/**
	 * The form handler.
	 *
	 * @throws ToolError When WPForms did not load it.
	 */
	private static function handler(): object {
		$handler = wpforms()->obj( 'form' );
		if ( ! is_object( $handler ) ) {
			throw new ToolError( 'WPForms did not load its form handler.' );
		}
		return $handler;
	}

	/**
	 * {@inheritDoc}
	 */
	public function forms(): array {
		$posts = self::handler()->get(
			'',
			array(
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'cap'            => false,
			)
		);
		$out   = array();
		foreach ( is_array( $posts ) ? $posts : array() as $post ) {
			if ( $post instanceof \WP_Post && wpforms_current_user_can( 'view_form_single', $post->ID ) ) {
				$out[] = $this->summary( $post );
			}
		}
		return $out;
	}

	/**
	 * Summary row.
	 *
	 * @param \WP_Post $post Form post.
	 * @return array<string, mixed>
	 */
	private function summary( \WP_Post $post ): array {
		return array(
			'plugin'    => $this->slug(),
			'id'        => (int) $post->ID,
			'title'     => (string) $post->post_title,
			'entries'   => null !== self::obj( 'entry' ) && wpforms_current_user_can( 'view_entries_form_single', $post->ID ) ? (int) self::obj( 'entry' )->get_entries(
				array(
					'form_id' => (int) $post->ID,
					'status'  => '',
					'cap'     => false,
				),
				true
			) : null,
			'shortcode' => '[wpforms id="' . (int) $post->ID . '"]',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Form id.
	 * @throws ToolError When the form is missing or the user may not read it.
	 */
	public function form( int $id ): array {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || 'wpforms' !== $post->post_type ) {
			throw new ToolError( 'No WPForms form with id ' . (int) $id . '.' );
		}
		if ( ! wpforms_current_user_can( 'view_form_single', $id ) ) {
			throw new ToolError( 'WPForms does not allow your account to read this form.' );
		}
		$data     = (array) wpforms_decode( $post->post_content );
		$settings = (array) ( $data['settings'] ?? array() );
		$fields   = array();
		foreach ( (array) ( $data['fields'] ?? array() ) as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['id'] ) ) {
				continue;
			}
			$item = array(
				'name'     => (string) $field['id'],
				'label'    => (string) ( $field['label'] ?? '' ),
				'type'     => (string) ( $field['type'] ?? '' ),
				'required' => ! empty( $field['required'] ),
			);
			if ( in_array( $item['type'], self::CHOICE_TYPES, true ) ) {
				$item['choices'] = array_values( array_map( static fn( $c ) => (string) ( is_array( $c ) ? ( $c['label'] ?? '' ) : $c ), (array) ( $field['choices'] ?? array() ) ) );
			}
			$fields[] = $item;
		}
		$enabled       = ! empty( $settings['notification_enable'] );
		$notifications = array();
		foreach ( (array) ( $settings['notifications'] ?? array() ) as $n ) {
			$n               = (array) $n;
			$notifications[] = array(
				'name'      => (string) ( $n['notification_name'] ?? '' ),
				'active'    => $enabled,
				'recipient' => (string) ( $n['email'] ?? '' ),
				'sender'    => trim( (string) ( $n['sender_name'] ?? '' ) . ' <' . (string) ( $n['sender_address'] ?? '' ) . '>', ' <>' ),
				'reply_to'  => (string) ( $n['replyto'] ?? '' ),
				'cc'        => (string) ( $n['carboncopy'] ?? '' ),
				'subject'   => (string) ( $n['subject'] ?? '' ),
				'body'      => (string) ( $n['message'] ?? '' ),
			);
		}
		$messages = array();
		foreach ( (array) ( $settings['confirmations'] ?? array() ) as $key => $c ) {
			$c                                  = (array) $c;
			$messages[ 'confirmation_' . $key ] = 'message' === ( $c['type'] ?? 'message' ) ? wp_strip_all_tags( (string) ( $c['message'] ?? '' ) ) : (string) ( $c['type'] ?? '' ) . ': ' . (string) ( $c['redirect'] ?? $c['page'] ?? '' );
		}
		return $this->summary( $post ) + array(
			'description'   => (string) ( $settings['form_desc'] ?? '' ),
			'submit_text'   => (string) ( $settings['submit_text'] ?? '' ),
			'fields'        => $fields,
			'messages'      => (object) $messages,
			'notifications' => $notifications,
		);
	}

	/**
	 * Field kinds per WPForms field type.
	 */
	private const KINDS = array(
		'email'       => 'email',
		'phone'       => 'phone',
		'name'        => 'name',
		'address'     => 'address',
		'file-upload' => 'file',
		'password'    => 'password',
		'signature'   => 'file',
		'camera'      => 'file',
	);

	/**
	 * A WPForms Pro object handler (entry, entry_meta), or null on Lite.
	 *
	 * @param string $name Registry name.
	 */
	private static function obj( string $name ): ?object {
		$obj = wpforms()->obj( $name );
		return is_object( $obj ) ? $obj : null;
	}

	/**
	 * The entry handler, or a refusal on WPForms Lite.
	 *
	 * @throws ToolError When the site keeps no entries.
	 */
	private function entry_handler(): object {
		$handler = self::obj( 'entry' );
		if ( null === $handler ) {
			Forms::no_entries( $this, 'WPForms Lite does not save submissions on the site (it only emails them).' );
		}
		return $handler;
	}

	/**
	 * An entry row by id, or a ToolError.
	 *
	 * @param int $id Entry id.
	 * @throws ToolError When there is none.
	 */
	private function row( int $id ): object {
		$row = $id > 0 ? $this->entry_handler()->get( $id, array( 'cap' => false ) ) : null;
		if ( ! is_object( $row ) || empty( $row->form_id ) ) {
			throw new ToolError( 'No WPForms entry with id ' . (int) $id . '.' );
		}
		return $row;
	}

	/**
	 * Shared status of a WPForms entry status ('' for a normal entry; partial and abandoned count as active).
	 *
	 * @param string $raw WPForms status.
	 */
	private static function status( string $raw ): string {
		return match ( $raw ) {
			'spam'  => 'spam',
			'trash' => 'trash',
			default => 'active',
		};
	}

	/**
	 * One entry row in the shared entry shape.
	 *
	 * @param object $row Entry row.
	 * @return array<string, mixed>
	 */
	private static function normalize( object $row ): array {
		$fields = json_decode( (string) ( $row->fields ?? '' ), true );
		$out    = array();
		foreach ( is_array( $fields ) ? $fields : array() as $key => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$type  = (string) ( $field['type'] ?? '' );
			$name  = (string) ( $field['id'] ?? $key );
			$label = (string) ( $field['name'] ?? '' );
			$out[] = array(
				'name'  => $name,
				'label' => '' !== $label ? $label : $name,
				'kind'  => self::KINDS[ $type ] ?? Mask::kind_from_name( $label ),
				'value' => $field['value'] ?? '',
			);
		}
		$meta = json_decode( (string) ( $row->meta ?? '' ), true );
		return array(
			'plugin'  => 'wpforms',
			'id'      => (int) $row->entry_id,
			'form_id' => (int) $row->form_id,
			'date'    => (string) ( $row->date ?? '' ),
			'status'  => self::status( (string) ( $row->status ?? '' ) ),
			'read'    => isset( $row->viewed ) ? (bool) (int) $row->viewed : null,
			'starred' => isset( $row->starred ) ? (bool) (int) $row->starred : null,
			'subject' => null,
			'sender'  => null,
			'fields'  => $out,
			'meta'    => array(
				'ip'         => (string) ( $row->ip_address ?? '' ),
				'user_agent' => (string) ( $row->user_agent ?? '' ),
				'source_url' => is_array( $meta ) ? (string) ( $meta['page_url'] ?? '' ) : '',
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $form_id Form id.
	 * @param array<string, mixed> $query   Query.
	 * @throws ToolError When the site keeps no entries, the form is missing or the user may not read its entries.
	 */
	public function entries( int $form_id, array $query ): array {
		$handler = $this->entry_handler();
		$post    = get_post( $form_id );
		if ( ! $post instanceof \WP_Post || 'wpforms' !== $post->post_type ) {
			throw new ToolError( 'No WPForms form with id ' . (int) $form_id . '.' );
		}
		if ( ! wpforms_current_user_can( 'view_entries_form_single', $form_id ) ) {
			throw new ToolError( 'WPForms does not allow your account to read entries of this form.' );
		}
		if ( '' !== (string) ( $query['search'] ?? '' ) ) {
			throw new ToolError( 'Searching WPForms entries is not supported; filter by status and page through them instead.' );
		}
		$status         = (string) ( $query['status'] ?? 'active' );
		[ $page, $per ] = Forms::paging( $query );
		$args           = array(
			'form_id' => $form_id,
			'orderby' => 'entry_id',
			'order'   => 'DESC',
			'cap'     => false,
		);
		if ( 'all' !== $status ) {
			$args['status'] = 'active' === $status ? '' : $status;
		}
		$rows    = $handler->get_entries(
			$args + array(
				'number' => $per,
				'offset' => ( $page - 1 ) * $per,
			)
		);
		$entries = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_object( $row ) ) {
				$entries[] = self::normalize( $row );
			}
		}
		return array(
			'entries' => $entries,
			'total'   => (int) $handler->get_entries( $args, true ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $entry_id Entry id.
	 * @throws ToolError When the entry is missing or the user may not read it.
	 */
	public function entry( int $entry_id ): array {
		$row = $this->row( $entry_id );
		if ( ! $this->allowed( 'read', $entry_id ) ) {
			throw new ToolError( 'WPForms does not allow your account to read this entry.' );
		}
		$notes = array();
		$meta  = self::obj( 'entry_meta' );
		$rows  = null !== $meta ? $meta->get_meta(
			array(
				'entry_id' => $entry_id,
				'type'     => 'note',
				'number'   => 100,
			)
		) : array();
		foreach ( is_array( $rows ) ? $rows : array() as $note ) {
			if ( is_object( $note ) ) {
				$user    = get_userdata( (int) ( $note->user_id ?? 0 ) );
				$notes[] = array(
					'id'     => (int) $note->id,
					'author' => $user ? (string) $user->display_name : '',
					'date'   => (string) ( $note->date ?? '' ),
					'text'   => wp_strip_all_tags( (string) ( $note->data ?? '' ) ),
				);
			}
		}
		return self::normalize( $row ) + array( 'notes' => $notes );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $action   read | edit | trash | spam | note.
	 * @param int    $entry_id Entry id.
	 */
	public function allowed( string $action, int $entry_id ): bool {
		try {
			$row = $this->row( $entry_id );
		} catch ( ToolError $e ) {
			return false;
		}
		$cap = match ( $action ) {
			'read'  => 'view_entries_form_single',
			'trash' => 'delete_entries_form_single',
			default => 'edit_entries_form_single',
		};
		return (bool) wpforms_current_user_can( $cap, (int) $row->form_id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $entry_id Entry id.
	 */
	public function state( int $entry_id ): array {
		$row = $this->row( $entry_id );
		return array(
			'status'     => self::status( (string) ( $row->status ?? '' ) ),
			'read'       => (bool) (int) ( $row->viewed ?? 0 ),
			'starred'    => (bool) (int) ( $row->starred ?? 0 ),
			'raw_status' => (string) ( $row->status ?? '' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $entry_id Entry id.
	 * @param array<string, mixed> $change   Change.
	 */
	public function external_effects( int $entry_id, array $change ): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $entry_id Entry id.
	 * @param array<string, mixed> $change   Change or saved state.
	 * @param bool                 $restore  Undo.
	 * @throws ToolError When WPForms does not save the change.
	 */
	public function change( int $entry_id, array $change, bool $restore = false ): void {
		$state = $this->state( $entry_id );
		$data  = array();
		if ( $restore ) {
			$data['status'] = (string) ( $change['raw_status'] ?? $state['raw_status'] );
		} elseif ( isset( $change['status'] ) && $change['status'] !== $state['status'] ) {
			$data['status'] = 'active' === $change['status'] ? '' : (string) $change['status'];
		}
		if ( isset( $change['read'] ) ) {
			$data['viewed'] = $change['read'] ? 1 : 0;
		}
		if ( isset( $change['starred'] ) ) {
			$data['starred'] = $change['starred'] ? 1 : 0;
		}
		if ( ! $data ) {
			return;
		}
		if ( ! $this->entry_handler()->update( $entry_id, $data, '', 'entry' ) ) {
			throw new ToolError( 'WPForms did not save the change to entry ' . (int) $entry_id . '.' );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $entry_id Entry id.
	 * @param string $text     Note.
	 * @throws ToolError When WPForms does not store the note.
	 */
	public function add_note( int $entry_id, string $text ): int {
		$row  = $this->row( $entry_id );
		$meta = self::obj( 'entry_meta' );
		$id   = null !== $meta ? (int) $meta->add(
			array(
				'entry_id' => $entry_id,
				'form_id'  => (int) $row->form_id,
				'user_id'  => get_current_user_id(),
				'type'     => 'note',
				'data'     => wpautop( esc_html( $text ) ),
			),
			'entry_meta'
		) : 0;
		if ( $id <= 0 ) {
			throw new ToolError( 'WPForms did not store the note.' );
		}
		return $id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $entry_id Entry id.
	 * @param int $note_id  Note id.
	 */
	public function delete_note( int $entry_id, int $note_id ): void {
		$meta = self::obj( 'entry_meta' );
		if ( null !== $meta ) {
			$meta->delete( $note_id );
		}
	}
}
