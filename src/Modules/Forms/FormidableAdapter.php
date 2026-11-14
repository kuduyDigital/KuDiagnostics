<?php
/**
 * Formidable Forms through its own model classes.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Forms;

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Forms through FrmForm, fields through FrmField::get_all_for_form(), entries through FrmEntry::getAll() / getOne()
 * (source of Formidable Forms 7.0, RESEARCH.md §10b). Entries are read only: the free plugin has no trash, read or
 * starred flag and no notes, and deleting is permanent, which KuDiagnostics does not do. Spam entries (status 4,
 * stored since 7.0) can be listed. Permissions are Formidable's own capabilities (frm_view_forms, frm_view_entries,
 * frm_edit_entries); like Formidable itself, administrators always pass.
 */
final class FormidableAdapter implements FormsAdapter {

	/**
	 * Formidable's status number for a spam entry (FrmEntriesHelper::SPAM_ENTRY_STATUS).
	 */
	private const SPAM = 4;

	/**
	 * Entry kinds per Formidable field type.
	 */
	private const KINDS = array(
		'email'    => 'email',
		'phone'    => 'phone',
		'name'     => 'name',
		'address'  => 'address',
		'file'     => 'file',
		'password' => 'password',
	);

	/**
	 * Field types that hold no submitted value.
	 */
	private const NO_VALUE = array( 'submit', 'html', 'divider', 'end_divider', 'break', 'captcha', 'summary', 'form' );

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'formidable';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'Formidable Forms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function active(): bool {
		return class_exists( 'FrmForm' ) && class_exists( 'FrmEntry' ) && class_exists( 'FrmField' ) && class_exists( 'FrmAppHelper' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function version(): string {
		return (string) \FrmAppHelper::plugin_version();
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
	 * Formidable permission check.
	 *
	 * @param string $cap Formidable capability.
	 */
	private static function can( string $cap ): bool {
		// Formidable gives an administrator every frm_* capability the first time they open wp-admin
		// (FrmAppHelper::maybe_add_permissions(), 7.0), so an administrator is allowed here before that has happened.
		// phpcs:ignore WordPress.WP.Capabilities.RoleFound -- the same role check Formidable makes.
		return current_user_can( 'administrator' ) || \FrmAppHelper::current_user_can( $cap );
	}

	/**
	 * {@inheritDoc}
	 */
	public function forms(): array {
		if ( ! self::can( 'frm_view_forms' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) \FrmForm::getAll(
			array(
				'is_template' => 0,
				'status'      => array( null, '', 'published' ),
			),
			'id ASC'
		) as $form ) {
			if ( is_object( $form ) ) {
				$out[] = $this->summary( $form );
			}
		}
		return $out;
	}

	/**
	 * A form row, or a ToolError.
	 *
	 * @param int $id Form id.
	 * @throws ToolError When there is none.
	 */
	private static function find( int $id ): object {
		$form = $id > 0 ? \FrmForm::getOne( $id ) : null;
		if ( ! is_object( $form ) ) {
			throw new ToolError( 'No Formidable form with id ' . (int) $id . '.' );
		}
		return $form;
	}

	/**
	 * Summary row.
	 *
	 * @param object $form Form row.
	 * @return array<string, mixed>
	 */
	private function summary( object $form ): array {
		$id = (int) $form->id;
		return array(
			'plugin'    => $this->slug(),
			'id'        => $id,
			'title'     => (string) $form->name,
			'status'    => (string) ( $form->status ?? '' ),
			'entries'   => self::can( 'frm_view_entries' ) ? (int) \FrmEntry::getRecordCount( $id ) : null,
			'shortcode' => '[formidable id="' . $id . '"]',
		);
	}

	/**
	 * Fields of a form with the kind used for masking.
	 *
	 * @param int $form_id Form id.
	 * @return list<array<string, mixed>>
	 */
	private static function fields( int $form_id ): array {
		$out = array();
		foreach ( (array) \FrmField::get_all_for_form( $form_id ) as $field ) {
			if ( ! is_object( $field ) || in_array( (string) $field->type, self::NO_VALUE, true ) ) {
				continue;
			}
			$label = (string) $field->name;
			$item  = array(
				'id'       => (int) $field->id,
				'name'     => (string) $field->field_key,
				'label'    => '' !== $label ? $label : (string) $field->field_key,
				'type'     => (string) $field->type,
				'required' => ! empty( $field->required ),
				'kind'     => self::KINDS[ (string) $field->type ] ?? Mask::kind_from_name( $label ),
			);
			if ( is_array( $field->options ?? null ) && $field->options ) {
				$item['choices'] = array_values( array_map( static fn( $o ) => (string) ( is_array( $o ) ? ( $o['value'] ?? $o['label'] ?? '' ) : $o ), $field->options ) );
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Form id.
	 * @throws ToolError When the form is missing or the user may not see forms.
	 */
	public function form( int $id ): array {
		$form = self::find( $id );
		if ( ! self::can( 'frm_view_forms' ) ) {
			throw new ToolError( 'Formidable Forms does not allow your account to see forms.' );
		}
		$options       = is_array( $form->options ?? null ) ? $form->options : array();
		$notifications = array();
		if ( class_exists( 'FrmFormAction' ) ) {
			foreach ( (array) \FrmFormAction::get_action_for_form( $id, 'email' ) as $action ) {
				$c = is_object( $action ) && is_array( $action->post_content ?? null ) ? $action->post_content : null;
				if ( null === $c ) {
					continue;
				}
				$notifications[] = array(
					'name'      => (string) $action->post_title,
					'active'    => 'publish' === (string) $action->post_status,
					'recipient' => (string) ( $c['email_to'] ?? '' ),
					'sender'    => (string) ( $c['from'] ?? '' ),
					'reply_to'  => (string) ( $c['reply_to'] ?? '' ),
					'cc'        => (string) ( $c['cc'] ?? '' ),
					'bcc'       => (string) ( $c['bcc'] ?? '' ),
					'subject'   => (string) ( $c['email_subject'] ?? '' ),
					'body'      => (string) ( $c['email_message'] ?? '' ),
				);
			}
		}
		return $this->summary( $form ) + array(
			'description'   => (string) ( $form->description ?? '' ),
			'submit_text'   => (string) ( $options['submit_value'] ?? '' ),
			'fields'        => array_map(
				static fn( array $f ) => array_diff_key(
					$f,
					array(
						'kind' => true,
						'id'   => true,
					)
				),
				self::fields( $id )
			),
			'messages'      => (object) array_filter( array( 'success' => wp_strip_all_tags( (string) ( $options['success_msg'] ?? '' ) ) ) ),
			'notifications' => $notifications,
		);
	}

	/**
	 * One entry row in the shared entry shape.
	 *
	 * @param object                     $entry  Entry row with metas.
	 * @param list<array<string, mixed>> $fields Form fields.
	 * @return array<string, mixed>
	 */
	private static function normalize( object $entry, array $fields ): array {
		$metas = is_array( $entry->metas ?? null ) ? $entry->metas : array();
		$out   = array();
		foreach ( $fields as $field ) {
			$value = $metas[ $field['id'] ] ?? null;
			if ( null === $value || '' === $value || array() === $value ) {
				continue;
			}
			$out[] = array(
				'name'  => $field['name'],
				'label' => $field['label'],
				'kind'  => $field['kind'],
				'value' => $value,
			);
		}
		$about = maybe_unserialize( $entry->description ?? '' );
		$about = is_array( $about ) ? $about : array();
		return array(
			'plugin'  => 'formidable',
			'id'      => (int) $entry->id,
			'form_id' => (int) $entry->form_id,
			'date'    => (string) ( $entry->created_at ?? '' ),
			'status'  => self::SPAM === (int) ( $entry->is_draft ?? 0 ) ? 'spam' : 'active',
			'read'    => null,
			'starred' => null,
			'subject' => null,
			'sender'  => null,
			'fields'  => $out,
			'meta'    => array(
				'ip'         => (string) ( $entry->ip ?? '' ),
				'user_agent' => (string) ( $about['browser'] ?? '' ),
				'source_url' => (string) ( $about['referrer'] ?? '' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $form_id Form id.
	 * @param array<string, mixed> $query   Query.
	 * @throws ToolError When the form is missing, the user may not read entries, or a search is asked for.
	 */
	public function entries( int $form_id, array $query ): array {
		self::find( $form_id );
		if ( ! self::can( 'frm_view_entries' ) ) {
			throw new ToolError( 'Formidable Forms does not allow your account to read entries.' );
		}
		if ( '' !== (string) ( $query['search'] ?? '' ) ) {
			throw new ToolError( 'Searching Formidable entries is not supported; filter by status and page through them instead.' );
		}
		$status = (string) ( $query['status'] ?? 'active' );
		if ( 'trash' === $status ) {
			return array(
				'entries' => array(),
				'total'   => 0,
			);
		}
		$where = array( 'it.form_id' => $form_id );
		if ( 'spam' === $status ) {
			$where['it.is_draft'] = self::SPAM;
		} elseif ( 'all' === $status ) {
			$where['it.is_draft'] = array( 0, 1, self::SPAM );
		}
		[ $page, $per ] = Forms::paging( $query );
		$rows           = \FrmEntry::getAll( $where, ' ORDER BY it.id DESC', ' LIMIT ' . ( ( $page - 1 ) * $per ) . ',' . $per, true, false );
		$fields         = self::fields( $form_id );
		$entries        = array();
		foreach ( (array) $rows as $row ) {
			if ( is_object( $row ) ) {
				$entries[] = self::normalize( $row, $fields );
			}
		}
		return array(
			'entries' => $entries,
			'total'   => (int) ( 'active' === $status ? \FrmEntry::getRecordCount( $form_id ) : \FrmEntry::getRecordCount( $where ) ),
		);
	}

	/**
	 * An entry row with its values, or a ToolError.
	 *
	 * @param int $id Entry id.
	 * @throws ToolError When there is none.
	 */
	private static function row( int $id ): object {
		$entry = $id > 0 ? \FrmEntry::getOne( $id, true ) : null;
		if ( ! is_object( $entry ) ) {
			throw new ToolError( 'No Formidable entry with id ' . (int) $id . '.' );
		}
		return $entry;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $entry_id Entry id.
	 * @throws ToolError When the entry is missing or the user may not read it.
	 */
	public function entry( int $entry_id ): array {
		$entry = self::row( $entry_id );
		if ( ! self::can( 'frm_view_entries' ) ) {
			throw new ToolError( 'Formidable Forms does not allow your account to read this entry.' );
		}
		return self::normalize( $entry, self::fields( (int) $entry->form_id ) ) + array( 'notes' => array() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $action   read | edit | trash | spam | note.
	 * @param int    $entry_id Entry id.
	 */
	public function allowed( string $action, int $entry_id ): bool {
		if ( $entry_id <= 0 || ! is_object( \FrmEntry::getOne( $entry_id ) ) ) {
			return false;
		}
		return self::can( 'read' === $action ? 'frm_view_entries' : 'frm_edit_entries' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $entry_id Entry id.
	 */
	public function state( int $entry_id ): array {
		$entry = self::row( $entry_id );
		return array(
			'status'  => self::SPAM === (int) ( $entry->is_draft ?? 0 ) ? 'spam' : 'active',
			'read'    => null,
			'starred' => null,
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
	 * @param array<string, mixed> $change   Change.
	 * @param bool                 $restore  Undo.
	 * @throws ToolError Always: entries are read only here.
	 */
	public function change( int $entry_id, array $change, bool $restore = false ): void {
		throw new ToolError( 'Formidable entries are read only here: the plugin has no trash, read or starred flag, and deleting an entry is permanent. Nothing was changed.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $entry_id Entry id.
	 * @param string $text     Note.
	 * @throws ToolError Always: no notes in the free plugin.
	 */
	public function add_note( int $entry_id, string $text ): int {
		throw new ToolError( 'Formidable Forms (free) has no notes on entries. Nothing was changed.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $entry_id Entry id.
	 * @param int $note_id  Note id.
	 */
	public function delete_note( int $entry_id, int $note_id ): void {
		// No notes in the free plugin.
	}
}
