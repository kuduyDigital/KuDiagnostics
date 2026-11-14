<?php
/**
 * Gravity Forms through its public GFAPI class.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Forms;

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Forms, entries and notes through GFAPI (get_forms, get_form, get_entries, count_entries, get_entry,
 * update_entry_property, get_notes, add_note, delete_note), as documented on docs.gravityforms.com (D28 A). GFAPI does
 * not check permissions, so this adapter does: Gravity's capabilities gravityforms_view_entries, _edit_entries,
 * _delete_entries, _view_entry_notes, _edit_entry_notes, _edit_forms, through GFCommon::current_user_can_any(), falling back to
 * current_user_can() with gform_full_access. Built from the docs only: behaviour against
 * a licensed copy is UNVERIFIED. Entries are never deleted permanently (delete_entry is not used).
 */
final class GravityAdapter implements FormsAdapter {

	/**
	 * Entry kinds per Gravity field type.
	 */
	private const KINDS = array(
		'email'      => 'email',
		'phone'      => 'phone',
		'name'       => 'name',
		'address'    => 'address',
		'fileupload' => 'file',
		'post_image' => 'file',
		'signature'  => 'file',
		'password'   => 'password',
	);

	/**
	 * Field types that hold no submitted value.
	 */
	private const NO_VALUE = array( 'html', 'section', 'page', 'captcha' );

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'gravityforms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'Gravity Forms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function active(): bool {
		return class_exists( 'GFAPI' ) && class_exists( 'GFForms' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function version(): string {
		return (string) \GFForms::$version;
	}

	/**
	 * {@inheritDoc}
	 */
	public function entries_store(): string {
		return 'plugin';
	}

	/**
	 * {@inheritDoc}
	 */
	public function abilities(): ?bool {
		return null;
	}

	/**
	 * Gravity permission check: any one of the capabilities is enough.
	 *
	 * @param list<string> $caps Capabilities.
	 */
	private static function can( array $caps ): bool {
		try {
			// Gravity's own check: it also honours gform_full_access and role-editor plugins.
			return (bool) \GFCommon::current_user_can_any( $caps );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		foreach ( array_merge( $caps, array( 'gform_full_access' ) ) as $cap ) {
			if ( current_user_can( $cap ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the user may read entries.
	 */
	private static function can_read(): bool {
		return self::can( array( 'gravityforms_view_entries', 'gravityforms_edit_entries' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function forms(): array {
		if ( ! self::can( array( 'gravityforms_edit_forms', 'gravityforms_view_entries' ) ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) \GFAPI::get_forms( null, false ) as $form ) {
			if ( is_array( $form ) ) {
				$out[] = $this->summary( $form );
			}
		}
		return $out;
	}

	/**
	 * A form by id, or a ToolError.
	 *
	 * @param int $id Form id.
	 * @return array<string, mixed>
	 * @throws ToolError When there is none.
	 */
	private static function find( int $id ): array {
		$form = $id > 0 ? \GFAPI::get_form( $id ) : false;
		if ( ! is_array( $form ) ) {
			throw new ToolError( 'No Gravity Forms form with id ' . (int) $id . '.' );
		}
		return $form;
	}

	/**
	 * Summary row.
	 *
	 * @param array<string, mixed> $form Form object.
	 * @return array<string, mixed>
	 */
	private function summary( array $form ): array {
		$id = (int) ( $form['id'] ?? 0 );
		return array(
			'plugin'    => $this->slug(),
			'id'        => $id,
			'title'     => (string) ( $form['title'] ?? '' ),
			'status'    => ! empty( $form['is_active'] ) ? 'active' : 'inactive',
			'entries'   => self::can_read() ? (int) \GFAPI::count_entries( $id, array( 'status' => 'active' ) ) : null,
			'shortcode' => '[gravityform id="' . $id . '"]',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Form id.
	 * @throws ToolError When the form is missing or the user may not read it.
	 */
	public function form( int $id ): array {
		$form = self::find( $id );
		if ( ! self::can( array( 'gravityforms_edit_forms' ) ) ) {
			throw new ToolError( 'Gravity Forms does not allow your account to edit forms.' );
		}
		$notifications = array();
		foreach ( (array) ( $form['notifications'] ?? array() ) as $n ) {
			$n               = (array) $n;
			$notifications[] = array(
				'name'      => (string) ( $n['name'] ?? '' ),
				'active'    => ! isset( $n['isActive'] ) || ! empty( $n['isActive'] ),
				'recipient' => 'field' === ( $n['toType'] ?? '' ) ? 'value of field ' . (string) ( $n['to'] ?? '' ) : (string) ( $n['to'] ?? '' ),
				'sender'    => trim( (string) ( $n['fromName'] ?? '' ) . ' <' . (string) ( $n['from'] ?? '' ) . '>', ' <>' ),
				'reply_to'  => (string) ( $n['replyTo'] ?? '' ),
				'bcc'       => (string) ( $n['bcc'] ?? '' ),
				'subject'   => (string) ( $n['subject'] ?? '' ),
				'body'      => (string) ( $n['message'] ?? '' ),
			);
		}
		$messages = array();
		foreach ( (array) ( $form['confirmations'] ?? array() ) as $key => $c ) {
			$c                                  = (array) $c;
			$type                               = (string) ( $c['type'] ?? 'message' );
			$messages[ 'confirmation_' . $key ] = 'message' === $type ? wp_strip_all_tags( (string) ( $c['message'] ?? '' ) ) : $type . ': ' . (string) ( $c['url'] ?? $c['pageId'] ?? '' );
		}
		return $this->summary( $form ) + array(
			'description'   => (string) ( $form['description'] ?? '' ),
			'fields'        => array_map(
				static fn( array $f ) => array_diff_key( $f, array( 'kind' => true ) ),
				self::fields( $form )
			),
			'messages'      => (object) $messages,
			'notifications' => $notifications,
		);
	}

	/**
	 * Fields of a form with the kind used for masking. Multi-input fields (name, address) list one field; their values
	 * are joined from the input ids (1.3, 1.6).
	 *
	 * @param array<string, mixed> $form Form object.
	 * @return list<array<string, mixed>>
	 */
	private static function fields( array $form ): array {
		$out = array();
		foreach ( (array) ( $form['fields'] ?? array() ) as $field ) {
			$f    = is_object( $field ) ? get_object_vars( $field ) : (array) $field;
			$type = (string) ( $f['type'] ?? '' );
			if ( ! isset( $f['id'] ) || in_array( $type, self::NO_VALUE, true ) ) {
				continue;
			}
			$label  = (string) ( $f['adminLabel'] ?? '' );
			$label  = '' !== $label ? $label : (string) ( $f['label'] ?? '' );
			$inputs = array();
			foreach ( is_array( $f['inputs'] ?? null ) ? $f['inputs'] : array() as $input ) {
				if ( is_array( $input ) && isset( $input['id'] ) && empty( $input['isHidden'] ) ) {
					$inputs[] = (string) $input['id'];
				}
			}
			$item = array(
				'name'     => (string) $f['id'],
				'label'    => '' !== $label ? $label : (string) $f['id'],
				'type'     => $type,
				'required' => ! empty( $f['isRequired'] ),
				'inputs'   => $inputs,
				'kind'     => self::KINDS[ $type ] ?? Mask::kind_from_name( $label ),
			);
			if ( is_array( $f['choices'] ?? null ) && $f['choices'] ) {
				$item['choices'] = array_values( array_map( static fn( $c ) => (string) ( is_array( $c ) ? ( $c['value'] ?? $c['text'] ?? '' ) : $c ), $f['choices'] ) );
			}
			$out[] = $item;
		}//end foreach
		return $out;
	}

	/**
	 * An entry by id, or a ToolError.
	 *
	 * @param int $id Entry id.
	 * @return array<string, mixed>
	 * @throws ToolError When there is none.
	 */
	private static function find_entry( int $id ): array {
		$entry = $id > 0 ? \GFAPI::get_entry( $id ) : null;
		if ( ! is_array( $entry ) ) {
			throw new ToolError( 'No Gravity Forms entry with id ' . (int) $id . '.' );
		}
		return $entry;
	}

	/**
	 * One entry in the shared entry shape.
	 *
	 * @param array<string, mixed>       $entry  Entry object.
	 * @param list<array<string, mixed>> $fields Form fields.
	 * @return array<string, mixed>
	 */
	private static function normalize( array $entry, array $fields ): array {
		$out = array();
		foreach ( $fields as $field ) {
			if ( $field['inputs'] ) {
				$parts = array();
				foreach ( $field['inputs'] as $input ) {
					$value = (string) ( $entry[ $input ] ?? '' );
					if ( '' !== $value ) {
						$parts[] = $value;
					}
				}
				$value = 'checkbox' === $field['type'] ? $parts : implode( ' ', $parts );
			} else {
				$value = $entry[ $field['name'] ] ?? '';
			}
			if ( '' === $value || array() === $value ) {
				continue;
			}
			$out[] = array(
				'name'  => $field['name'],
				'label' => $field['label'],
				'kind'  => $field['kind'],
				'value' => $value,
			);
		}//end foreach
		$status = (string) ( $entry['status'] ?? 'active' );
		return array(
			'plugin'  => 'gravityforms',
			'id'      => (int) ( $entry['id'] ?? 0 ),
			'form_id' => (int) ( $entry['form_id'] ?? 0 ),
			'date'    => (string) ( $entry['date_created'] ?? '' ),
			'status'  => in_array( $status, Forms::STATUSES, true ) ? $status : 'active',
			'read'    => ! empty( $entry['is_read'] ),
			'starred' => ! empty( $entry['is_starred'] ),
			'subject' => null,
			'sender'  => null,
			'fields'  => $out,
			'meta'    => array(
				'ip'         => (string) ( $entry['ip'] ?? '' ),
				'user_agent' => (string) ( $entry['user_agent'] ?? '' ),
				'source_url' => (string) ( $entry['source_url'] ?? '' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $form_id Form id.
	 * @param array<string, mixed> $query   Query.
	 * @throws ToolError When the form is missing or the user may not read its entries.
	 */
	public function entries( int $form_id, array $query ): array {
		$form = self::find( $form_id );
		if ( ! self::can_read() ) {
			throw new ToolError( 'Gravity Forms does not allow your account to read entries.' );
		}
		$criteria = array();
		$status   = (string) ( $query['status'] ?? 'active' );
		if ( 'all' !== $status ) {
			$criteria['status'] = $status;
		}
		$search = (string) ( $query['search'] ?? '' );
		if ( '' !== $search ) {
			$criteria['field_filters'] = array(
				array(
					'key'      => 0,
					'operator' => 'contains',
					'value'    => $search,
				),
			);
		}
		[ $page, $per ] = Forms::paging( $query );
		$total          = 0;
		$rows           = \GFAPI::get_entries(
			$form_id,
			$criteria,
			array(
				'key'       => 'id',
				'direction' => 'DESC',
			),
			array(
				'offset'    => ( $page - 1 ) * $per,
				'page_size' => $per,
			),
			$total
		);
		if ( is_wp_error( $rows ) ) {
			throw new ToolError( 'Gravity Forms did not return entries: ' . esc_html( $rows->get_error_message() ) );
		}
		$fields  = self::fields( $form );
		$entries = array();
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) ) {
				$entries[] = self::normalize( $row, $fields );
			}
		}
		return array(
			'entries' => $entries,
			'total'   => (int) $total,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $entry_id Entry id.
	 * @throws ToolError When the entry is missing or the user may not read it.
	 */
	public function entry( int $entry_id ): array {
		$entry = self::find_entry( $entry_id );
		if ( ! $this->allowed( 'read', $entry_id ) ) {
			throw new ToolError( 'Gravity Forms does not allow your account to read this entry.' );
		}
		$notes = array();
		if ( self::can( array( 'gravityforms_view_entry_notes', 'gravityforms_edit_entry_notes' ) ) ) {
			$rows = \GFAPI::get_notes(
				array( 'entry_id' => $entry_id ),
				array(
					'key'       => 'id',
					'direction' => 'DESC',
				)
			);
			foreach ( is_array( $rows ) ? $rows : array() as $note ) {
				if ( is_object( $note ) ) {
					$notes[] = array(
						'id'     => (int) $note->id,
						'author' => (string) ( $note->user_name ?? '' ),
						'date'   => (string) ( $note->date_created ?? '' ),
						'text'   => (string) ( $note->value ?? '' ),
					);
				}
			}
		}
		return self::normalize( $entry, self::fields( self::find( (int) ( $entry['form_id'] ?? 0 ) ) ) ) + array( 'notes' => $notes );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $action   read | edit | trash | spam | note.
	 * @param int    $entry_id Entry id.
	 */
	public function allowed( string $action, int $entry_id ): bool {
		if ( $entry_id <= 0 || ! is_array( \GFAPI::get_entry( $entry_id ) ) ) {
			return false;
		}
		return match ( $action ) {
			'read'  => self::can_read(),
			'trash' => self::can( array( 'gravityforms_delete_entries' ) ),
			'note'  => self::can( array( 'gravityforms_edit_entry_notes' ) ),
			default => self::can( array( 'gravityforms_edit_entries' ) ),
		};
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $entry_id Entry id.
	 */
	public function state( int $entry_id ): array {
		$entry  = self::find_entry( $entry_id );
		$status = (string) ( $entry['status'] ?? 'active' );
		return array(
			'status'  => in_array( $status, Forms::STATUSES, true ) ? $status : 'active',
			'read'    => ! empty( $entry['is_read'] ),
			'starred' => ! empty( $entry['is_starred'] ),
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
	 * @throws ToolError When Gravity Forms does not save the change.
	 */
	public function change( int $entry_id, array $change, bool $restore = false ): void {
		$state = $this->state( $entry_id );
		$set   = array();
		if ( isset( $change['status'] ) && $change['status'] !== $state['status'] ) {
			$set['status'] = (string) $change['status'];
		}
		if ( isset( $change['read'] ) && (bool) $change['read'] !== $state['read'] ) {
			$set['is_read'] = $change['read'] ? 1 : 0;
		}
		if ( isset( $change['starred'] ) && (bool) $change['starred'] !== $state['starred'] ) {
			$set['is_starred'] = $change['starred'] ? 1 : 0;
		}
		foreach ( $set as $property => $value ) {
			$result = \GFAPI::update_entry_property( $entry_id, $property, $value );
			if ( false === $result || is_wp_error( $result ) ) {
				throw new ToolError( 'Gravity Forms did not save ' . esc_html( $property ) . ' on entry ' . (int) $entry_id . '.' );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $entry_id Entry id.
	 * @param string $text     Note.
	 * @throws ToolError When Gravity Forms does not store the note.
	 */
	public function add_note( int $entry_id, string $text ): int {
		self::find_entry( $entry_id );
		$user = wp_get_current_user();
		$id   = \GFAPI::add_note( $entry_id, (int) $user->ID, (string) $user->display_name, $text, 'user' );
		if ( is_wp_error( $id ) || (int) $id <= 0 ) {
			throw new ToolError( 'Gravity Forms did not store the note.' );
		}
		return (int) $id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $entry_id Entry id.
	 * @param int $note_id  Note id.
	 */
	public function delete_note( int $entry_id, int $note_id ): void {
		\GFAPI::delete_note( $note_id );
	}
}
