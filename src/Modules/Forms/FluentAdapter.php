<?php
/**
 * Fluent Forms through its own models, API and services.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Forms;

use FluentForm\App\Models\Form;
use FluentForm\App\Models\Submission;
use FluentForm\App\Models\SubmissionMeta;
use FluentForm\App\Modules\Acl\Acl;
use FluentForm\App\Services\Submission\SubmissionService;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Forms and entries in Fluent Forms' own tables, read with its models and fluentFormApi(), changed with its
 * SubmissionService (the service its admin screens use). Permissions: Acl::hasPermission( $permission, $form_id ),
 * which also applies Fluent's per-form manager scope. Fluent's own abilities (off by default) are not used (D15 A).
 */
final class FluentAdapter implements FormsAdapter {

	/**
	 * Entry kinds per Fluent element.
	 */
	private const KINDS = array(
		'input_email'    => 'email',
		'phone'          => 'phone',
		'input_name'     => 'name',
		'address'        => 'address',
		'input_file'     => 'file',
		'input_image'    => 'file',
		'input_password' => 'password',
	);

	/**
	 * Elements that hold no submitted value.
	 */
	private const NO_VALUE = array( 'button', 'custom_html', 'section_break', 'shortcode', 'recaptcha', 'hcaptcha', 'turnstile', 'form_step', 'custom_submit_button' );

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'fluentform';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'Fluent Forms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function active(): bool {
		return function_exists( 'fluentFormApi' ) && class_exists( Submission::class ) && class_exists( Acl::class );
	}

	/**
	 * {@inheritDoc}
	 */
	public function version(): string {
		return defined( 'FLUENTFORM_VERSION' ) ? (string) FLUENTFORM_VERSION : '';
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
	public function abilities(): bool {
		$settings = get_option( '_fluentform_mcp_settings', array() );
		return is_array( $settings ) && 'yes' === ( $settings['enabled'] ?? '' );
	}

	/**
	 * Fluent permission check, scoped to a form.
	 *
	 * @param string|list<string> $permission Permission(s): any one is enough.
	 * @param int                 $form_id    Form id.
	 */
	private static function can( string|array $permission, int $form_id ): bool {
		return (bool) Acl::hasPermission( $permission, $form_id );
	}

	/**
	 * {@inheritDoc}
	 */
	public function forms(): array {
		$out = array();
		foreach ( Form::orderBy( 'id', 'ASC' )->get() as $form ) {
			if ( self::can( array( 'fluentform_forms_manager', 'fluentform_entries_viewer' ), (int) $form->id ) ) {
				$out[] = $this->summary( $form );
			}
		}
		return $out;
	}

	/**
	 * A form row by id, or a ToolError.
	 *
	 * @param int $id Form id.
	 * @throws ToolError When there is none.
	 */
	private static function find( int $id ): object {
		$form = $id > 0 ? Form::where( 'id', $id )->first() : null;
		if ( ! is_object( $form ) ) {
			throw new ToolError( 'No Fluent Forms form with id ' . (int) $id . '.' );
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
			'title'     => (string) $form->title,
			'status'    => (string) $form->status,
			'entries'   => self::can( 'fluentform_entries_viewer', $id ) ? (int) Submission::where( 'form_id', $id )->whereNotIn( 'status', array( 'trashed', 'spam' ) )->count() : null,
			'shortcode' => '[fluentform id="' . $id . '"]',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Form id.
	 * @throws ToolError When the form is missing or the user may not manage it.
	 */
	public function form( int $id ): array {
		$form = self::find( $id );
		if ( ! self::can( 'fluentform_forms_manager', $id ) ) {
			throw new ToolError( 'Fluent Forms does not allow your account to manage this form.' );
		}
		$props         = fluentFormApi( 'forms' )->form( $form );
		$notifications = array();
		foreach ( (array) $props->emailNotifications() as $row ) {
			$n               = (array) ( $row['settings'] ?? array() );
			$send            = (array) ( $n['sendTo'] ?? array() );
			$notifications[] = array(
				'name'      => (string) ( $n['name'] ?? '' ),
				'active'    => ! empty( $n['enabled'] ),
				'recipient' => match ( (string) ( $send['type'] ?? 'email' ) ) {
					'field'   => 'value of field ' . (string) ( $send['field'] ?? '' ),
					'routing' => 'conditional routing',
					default   => (string) ( $send['email'] ?? '' ),
				},
				'routing'   => 'routing' === ( $send['type'] ?? '' ) ? array_values( (array) ( $send['routing'] ?? array() ) ) : array(),
				'sender'    => trim( (string) ( $n['fromName'] ?? '' ) . ' <' . (string) ( $n['fromEmail'] ?? '' ) . '>', ' <>' ),
				'reply_to'  => (string) ( $n['replyTo'] ?? '' ),
				'bcc'       => (string) ( $n['bcc'] ?? '' ),
				'subject'   => (string) ( $n['subject'] ?? '' ),
				'body'      => (string) ( $n['message'] ?? '' ),
			);
		}
		$settings     = (array) $props->settings();
		$confirmation = (array) ( $settings['confirmation'] ?? array() );
		return $this->summary( $form ) + array(
			'fields'        => array_map(
				static fn( array $f ) => array_diff_key( $f, array( 'kind' => true ) ),
				self::fields( $form )
			),
			'messages'      => (object) array_filter(
				array(
					'confirmation' => (string) ( $confirmation['messageToShow'] ?? '' ),
					'redirect_to'  => (string) ( $confirmation['redirectTo'] ?? '' ),
				)
			),
			'notifications' => $notifications,
		);
	}

	/**
	 * Fields of a form (flattened out of containers), with the kind used for masking.
	 *
	 * @param object $form Form row.
	 * @return list<array<string, mixed>>
	 */
	private static function fields( object $form ): array {
		$definition = json_decode( (string) $form->form_fields, true );
		return self::flatten( (array) ( $definition['fields'] ?? array() ) );
	}

	/**
	 * Flatten Fluent field definitions.
	 *
	 * @param array<int|string, mixed> $fields Definitions.
	 * @return list<array<string, mixed>>
	 */
	private static function flatten( array $fields ): array {
		$out = array();
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$element = (string) ( $field['element'] ?? '' );
			if ( 'container' === $element ) {
				foreach ( (array) ( $field['columns'] ?? array() ) as $column ) {
					array_push( $out, ...self::flatten( (array) ( $column['fields'] ?? array() ) ) );
				}
				continue;
			}
			$name = (string) ( $field['attributes']['name'] ?? '' );
			if ( '' === $name || in_array( $element, self::NO_VALUE, true ) ) {
				continue;
			}
			$settings = (array) ( $field['settings'] ?? array() );
			$label    = (string) ( $settings['admin_field_label'] ?? '' );
			$label    = '' !== $label ? $label : (string) ( $settings['label'] ?? $name );
			$item     = array(
				'name'     => $name,
				'label'    => '' !== $label ? $label : $name,
				'type'     => $element,
				'required' => ! empty( $settings['validation_rules']['required']['value'] ),
				'kind'     => self::KINDS[ $element ] ?? Mask::kind_from_name( $name ),
			);
			$choices  = array();
			if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
				$choices = array_map( 'strval', array_keys( $field['options'] ) );
			} elseif ( isset( $settings['advanced_options'] ) && is_array( $settings['advanced_options'] ) ) {
				$choices = array_map( static fn( $o ) => (string) ( is_array( $o ) ? ( $o['value'] ?? '' ) : $o ), $settings['advanced_options'] );
			}
			if ( $choices ) {
				$item['choices'] = array_values( $choices );
			}
			$out[] = $item;
		}//end foreach
		return $out;
	}

	/**
	 * A submission row by id, or a ToolError.
	 *
	 * @param int $id Entry id.
	 * @throws ToolError When there is none.
	 */
	private static function submission( int $id ): object {
		$row = $id > 0 ? Submission::where( 'id', $id )->first() : null;
		if ( ! is_object( $row ) ) {
			throw new ToolError( 'No Fluent Forms entry with id ' . (int) $id . '.' );
		}
		return $row;
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
		if ( ! self::can( 'fluentform_entries_viewer', $form_id ) ) {
			throw new ToolError( 'Fluent Forms does not allow your account to read entries of this form.' );
		}
		$status         = (string) ( $query['status'] ?? 'active' );
		$search         = (string) ( $query['search'] ?? '' );
		$build          = static function () use ( $form_id, $status, $search ) {
			$q = Submission::where( 'form_id', $form_id );
			if ( 'active' === $status ) {
				$q->whereNotIn( 'status', array( 'trashed', 'spam' ) );
			} elseif ( 'spam' === $status ) {
				$q->where( 'status', 'spam' );
			} elseif ( 'trash' === $status ) {
				$q->where( 'status', 'trashed' );
			}
			if ( '' !== $search ) {
				$q->where( 'response', 'LIKE', '%' . $search . '%' );
			}
			return $q;
		};
		[ $page, $per ] = Forms::paging( $query );
		$fields         = self::fields( $form );
		$entries        = array();
		foreach ( $build()->orderBy( 'id', 'DESC' )->offset( ( $page - 1 ) * $per )->limit( $per )->get() as $row ) {
			$entries[] = self::normalize( $row, $fields );
		}
		return array(
			'entries' => $entries,
			'total'   => (int) $build()->count(),
		);
	}

	/**
	 * One submission in the shared entry shape.
	 *
	 * @param object                     $row    Submission row.
	 * @param list<array<string, mixed>> $fields Form fields.
	 * @return array<string, mixed>
	 */
	private static function normalize( object $row, array $fields ): array {
		$response = json_decode( (string) $row->response, true );
		$response = is_array( $response ) ? $response : array();
		$out      = array();
		foreach ( $fields as $field ) {
			if ( array_key_exists( $field['name'], $response ) ) {
				$out[] = array(
					'name'  => $field['name'],
					'label' => $field['label'],
					'kind'  => $field['kind'],
					'value' => $response[ $field['name'] ],
				);
				unset( $response[ $field['name'] ] );
			}
		}
		foreach ( $response as $name => $value ) {
			// Keys starting with _ are nonces, referrers and other internal values, not fields.
			if ( ! str_starts_with( (string) $name, '_' ) ) {
				$out[] = array(
					'name'  => (string) $name,
					'label' => (string) $name,
					'kind'  => Mask::kind_from_name( (string) $name ),
					'value' => $value,
				);
			}
		}
		$raw = (string) $row->status;
		return array(
			'plugin'  => 'fluentform',
			'id'      => (int) $row->id,
			'form_id' => (int) $row->form_id,
			'date'    => (string) $row->created_at,
			'status'  => self::status( $raw ),
			'read'    => in_array( $raw, array( 'read', 'unread' ), true ) ? 'read' === $raw : null,
			'starred' => (bool) $row->is_favourite,
			'subject' => null,
			'sender'  => null,
			'fields'  => $out,
			'meta'    => array(
				'ip'         => (string) $row->ip,
				'user_agent' => trim( (string) $row->browser . ' ' . (string) $row->device ),
				'source_url' => (string) $row->source_url,
			),
		);
	}

	/**
	 * Shared status of a Fluent status.
	 *
	 * @param string $raw Fluent status.
	 */
	private static function status( string $raw ): string {
		return match ( $raw ) {
			'trashed' => 'trash',
			'spam'    => 'spam',
			default   => 'active',
		};
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $entry_id Entry id.
	 * @throws ToolError When the entry is missing or the user may not read it.
	 */
	public function entry( int $entry_id ): array {
		$row = self::submission( $entry_id );
		if ( ! $this->allowed( 'read', $entry_id ) ) {
			throw new ToolError( 'Fluent Forms does not allow your account to read this entry.' );
		}
		$notes = array();
		foreach ( SubmissionMeta::where( 'response_id', $entry_id )->where( 'meta_key', '_notes' )->orderBy( 'id', 'DESC' )->get() as $note ) {
			$notes[] = array(
				'id'     => (int) $note->id,
				'author' => (string) $note->name,
				'date'   => (string) $note->created_at,
				'text'   => (string) $note->value,
			);
		}
		return self::normalize( $row, self::fields( self::find( (int) $row->form_id ) ) ) + array( 'notes' => $notes );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $action   read | edit | trash | spam | note.
	 * @param int    $entry_id Entry id.
	 */
	public function allowed( string $action, int $entry_id ): bool {
		$row = $entry_id > 0 ? Submission::where( 'id', $entry_id )->first() : null;
		if ( ! is_object( $row ) ) {
			return false;
		}
		return self::can( 'read' === $action ? 'fluentform_entries_viewer' : 'fluentform_manage_entries', (int) $row->form_id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $entry_id Entry id.
	 */
	public function state( int $entry_id ): array {
		$row = self::submission( $entry_id );
		$raw = (string) $row->status;
		return array(
			'status'     => self::status( $raw ),
			'read'       => in_array( $raw, array( 'read', 'unread' ), true ) ? 'read' === $raw : null,
			'starred'    => (bool) $row->is_favourite,
			'raw_status' => $raw,
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
	 * @throws ToolError When Fluent Forms refuses the change.
	 */
	public function change( int $entry_id, array $change, bool $restore = false ): void {
		$state   = $this->state( $entry_id );
		$service = new SubmissionService();
		if ( $restore ) {
			$raw = (string) ( $change['raw_status'] ?? $state['raw_status'] );
		} else {
			$to  = (string) ( $change['status'] ?? $state['status'] );
			$raw = match ( $to ) {
				'trash' => 'trashed',
				'spam'  => 'spam',
				default => isset( $change['read'] ) ? ( $change['read'] ? 'read' : 'unread' ) : ( 'active' === $state['status'] ? $state['raw_status'] : 'read' ),
			};
			if ( isset( $change['read'] ) && 'active' !== $to ) {
				throw new ToolError( 'Only entries that are not in spam or trash can be marked read or unread.' );
			}
		}
		try {
			if ( $raw !== $state['raw_status'] ) {
				$service->updateStatus(
					array(
						'entry_id' => $entry_id,
						'status'   => $raw,
					)
				);
			}
			if ( isset( $change['starred'] ) && (bool) $change['starred'] !== $state['starred'] ) {
				$service->toggleIsFavorite( $entry_id );
			}
		} catch ( \Exception $e ) {
			throw new ToolError( 'Fluent Forms refused the change: ' . esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $entry_id Entry id.
	 * @param string $text     Note.
	 * @throws ToolError When Fluent Forms does not store the note.
	 */
	public function add_note( int $entry_id, string $text ): int {
		self::submission( $entry_id );
		$result = ( new SubmissionService() )->storeNote(
			$entry_id,
			array(
				'note' => array(
					'content' => $text,
					'status'  => '',
				),
			)
		);
		$id     = (int) ( $result['insert_id'] ?? 0 );
		if ( $id <= 0 ) {
			throw new ToolError( 'Fluent Forms did not store the note.' );
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
		SubmissionMeta::where( 'id', $note_id )->where( 'response_id', $entry_id )->where( 'meta_key', '_notes' )->delete();
	}
}
