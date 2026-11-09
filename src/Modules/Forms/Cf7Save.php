<?php
/**
 * Tools: kuduy-forms-cf7-save, kuduy-forms-cf7-mail.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Forms;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Audit\MasksAuditArgs;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Packages;

/**
 * Contact Form 7 forms changed through CF7's own wpcf7_save_contact_form() (D16 A): "save" creates or patches the
 * title, form markup, messages and additional settings (kuduy:write); "mail" changes the two mails, which decide where
 * every future submission is emailed, so it needs kuduy:admin and is confirm-first when the routing (recipient,
 * sender, extra headers, second mail on/off) changes. CF7 filters markup and mail bodies with wpcf7_kses() for users
 * without unfiltered_html. Undo puts the previous title and properties back with the form's own save().
 */
final class Cf7Save implements Tool, Snapshottable, MasksAuditArgs {

	/**
	 * Keys of a CF7 mail.
	 */
	private const MAIL_KEYS = array( 'subject', 'sender', 'recipient', 'body', 'additional_headers', 'attachments', 'use_html', 'exclude_blank' );

	/**
	 * Keys that decide where mail goes.
	 */
	private const ROUTING = array( 'recipient', 'sender', 'additional_headers' );

	/**
	 * Mode.
	 *
	 * @param string $mode save | mail.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-forms-cf7-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'save' === $this->mode ? __( 'Save a Contact Form 7 form', 'kuduy-mcp' ) : __( 'Change Contact Form 7 mail', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'save' === $this->mode
			? 'Creates a Contact Form 7 form (no form_id) or patches one: title, form (form-tag markup such as [text* your-name] [email* your-email] [submit "Send"]), messages (keys as in kuduy-forms-get), additional_settings. Fields left out stay as they are. A new form starts from CF7\'s default template, including its mail to the site admin; change mail with kuduy-forms-cf7-mail. Returns CF7\'s configuration errors. Snapshot first for changes; undo with kuduy-snapshot-restore. Forms are not deleted through KUDUY.'
			: 'Changes the mails of a Contact Form 7 form: mail (always sent) and mail_2 (optional second mail, active true/false), each with subject, sender, recipient, body, additional_headers (Cc/Bcc/Reply-To lines), attachments, use_html, exclude_blank. These decide where every future submission is emailed: when recipient, sender, headers or mail_2 on/off change, the first call only reports the old and new routing (done: false). Ask the user, then call again with confirm: true. Needs the admin scope. Undo with kuduy-snapshot-restore.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$text = static fn( int $max ) => array(
			'type'      => 'string',
			'maxLength' => $max,
		);
		if ( 'save' === $this->mode ) {
			$properties = array(
				'form_id'             => Forms::id_input(),
				'title'               => $text( 200 ) + array( 'minLength' => 1 ),
				'form'                => $text( 50000 ),
				'messages'            => array(
					'type'                 => 'object',
					'additionalProperties' => $text( 2000 ),
				),
				'additional_settings' => $text( 10000 ),
			);
			$required   = array();
		} else {
			$mail       = array(
				'type'                 => 'object',
				'properties'           => array(
					'active'             => array( 'type' => 'boolean' ),
					'subject'            => $text( 500 ),
					'sender'             => $text( 500 ),
					'recipient'          => $text( 1000 ),
					'body'               => $text( 50000 ),
					'additional_headers' => $text( 2000 ),
					'attachments'        => $text( 2000 ),
					'use_html'           => array( 'type' => 'boolean' ),
					'exclude_blank'      => array( 'type' => 'boolean' ),
				),
				'additionalProperties' => false,
			);
			$properties = array(
				'form_id' => Forms::id_input(),
				'mail'    => $mail,
				'mail_2'  => $mail,
				'confirm' => Packages::confirm_input(),
			);
			$required   = array( 'form_id' );
		}//end if
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		$properties = array(
			'id'            => array( 'type' => 'integer' ),
			'title'         => array( 'type' => 'string' ),
			'config_errors' => array( 'type' => array( 'object', 'array' ) ),
			'_snapshot_id'  => array( 'type' => 'integer' ),
		);
		if ( 'save' === $this->mode ) {
			$properties['created'] = array( 'type' => 'boolean' );
			$required              = array( 'id', 'title', 'created', 'config_errors' );
		} else {
			$properties += array(
				'done'          => array(
					'type'        => 'boolean',
					'description' => 'False when only the impact was reported (confirm needed).',
				),
				'impact'        => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'notifications' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
			);
			$required    = array( 'id', 'title', 'done', 'impact', 'notifications' );
		}
		return array(
			'type'       => 'object',
			'properties' => $properties,
			'required'   => $required,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => 'mail' === $this->mode,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'save' === $this->mode ? 'kuduy:write' : 'kuduy:admin';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		$id = (int) ( $input['form_id'] ?? 0 );
		return $id > 0 ? array( 'wpcf7_edit_contact_form', $id ) : 'wpcf7_edit_contact_forms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return function_exists( 'wpcf7_save_contact_form' ) && class_exists( 'WPCF7_ContactForm' ) ? true : 'Contact Form 7 is not active';
	}

	/**
	 * Mail addresses and bodies stay out of audit rows and snapshot input.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	public function audit_args( array $args ): array {
		foreach ( array( 'mail', 'mail_2' ) as $name ) {
			if ( is_array( $args[ $name ] ?? null ) ) {
				foreach ( array( 'recipient', 'sender', 'additional_headers', 'body' ) as $key ) {
					if ( isset( $args[ $name ][ $key ] ) ) {
						$args[ $name ][ $key ] = Mask::MASK;
					}
				}
			}
		}
		return $args;
	}

	/**
	 * The two mails after the patch, as CF7 will store them.
	 *
	 * @param \WPCF7_ContactForm   $form  Form.
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, array<string, mixed>>
	 */
	private static function patched_mails( \WPCF7_ContactForm $form, array $input ): array {
		$out = array();
		foreach ( array( 'mail', 'mail_2' ) as $name ) {
			$current      = (array) $form->prop( $name );
			$out[ $name ] = is_array( $input[ $name ] ?? null ) ? array_merge( $current, $input[ $name ] ) : $current;
		}
		return $out;
	}

	/**
	 * Routing changes in plain words.
	 *
	 * @param \WPCF7_ContactForm   $form  Form.
	 * @param array<string, mixed> $input Arguments.
	 * @return list<string>
	 */
	private static function routing_changes( \WPCF7_ContactForm $form, array $input ): array {
		$changes = array();
		$labels  = array(
			'mail'   => 'Mail',
			'mail_2' => 'Mail (2)',
		);
		foreach ( self::patched_mails( $form, $input ) as $name => $mail ) {
			$old = (array) $form->prop( $name );
			if ( ! is_array( $input[ $name ] ?? null ) ) {
				continue;
			}
			if ( 'mail_2' === $name && array_key_exists( 'active', $input[ $name ] ) && ! empty( $old['active'] ) !== (bool) $input[ $name ]['active'] ) {
				$changes[] = $labels[ $name ] . ' is switched ' . ( $input[ $name ]['active'] ? 'on' : 'off' ) . '.';
			}
			foreach ( self::ROUTING as $key ) {
				$before = trim( (string) ( $old[ $key ] ?? '' ) );
				$after  = trim( (string) ( $mail[ $key ] ?? '' ) );
				if ( array_key_exists( $key, $input[ $name ] ) && $before !== $after ) {
					$changes[] = $labels[ $name ] . ' ' . str_replace( '_', ' ', $key ) . ': "' . $before . '" → "' . $after . '".';
				}
			}
		}
		return $changes;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		$id = (int) ( $input['form_id'] ?? 0 );
		if ( $id <= 0 ) {
			// A new form; CF7 forms are not deleted through KUDUY.
			return null;
		}
		$form = Cf7Adapter::find( $id );
		if ( 'mail' === $this->mode && self::routing_changes( $form, $input ) && empty( $input['confirm'] ) ) {
			return null;
		}
		return array(
			'object_type' => 'cf7_form',
			'object_id'   => (string) $id,
			'payload'     => array(
				'form_id'    => $id,
				'title'      => (string) $form->title(),
				'properties' => $form->get_properties(),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the form is gone.
	 */
	public function restore( array $payload ): void {
		$form = Cf7Adapter::find( (int) ( $payload['form_id'] ?? 0 ) );
		$form->set_title( (string) ( $payload['title'] ?? $form->title() ) );
		$form->set_properties( (array) ( $payload['properties'] ?? array() ) );
		$form->save();
	}

	/**
	 * CF7's configuration check (the warnings its editor shows).
	 *
	 * @param \WPCF7_ContactForm $form Form.
	 * @return array<int|string, mixed>|\stdClass
	 */
	private static function config_errors( \WPCF7_ContactForm $form ): array|\stdClass {
		if ( ! function_exists( 'wpcf7_validate_configuration' ) || ! wpcf7_validate_configuration() || ! class_exists( 'WPCF7_ConfigValidator' ) ) {
			return new \stdClass();
		}
		$validator = new \WPCF7_ConfigValidator( $form );
		$validator->validate();
		$validator->save();
		$errors = (array) $validator->collect_error_messages( array( 'decodes_html_entities' => true ) );
		return $errors ? $errors : new \stdClass();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the form is missing or CF7 does not save it.
	 */
	public function execute( array $input ): array {
		$id = (int) ( $input['form_id'] ?? 0 );
		if ( $id > 0 ) {
			Cf7Adapter::find( $id );
			AuditLog::target( 'cf7_form', (string) $id );
		}
		if ( 'mail' === $this->mode ) {
			return self::mail( Cf7Adapter::find( $id ), $input );
		}
		if ( $id <= 0 && empty( $input['title'] ) ) {
			throw new ToolError( 'A new form needs a title. Nothing was created.' );
		}
		$data = array( 'id' => $id > 0 ? $id : -1 );
		foreach ( array( 'title', 'form', 'additional_settings' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$data[ $key ] = (string) $input[ $key ];
			}
		}
		if ( isset( $input['messages'] ) ) {
			$current          = $id > 0 ? (array) Cf7Adapter::find( $id )->prop( 'messages' ) : array();
			$data['messages'] = array_merge( $current, array_map( 'strval', (array) $input['messages'] ) );
		}
		$form = wpcf7_save_contact_form( $data, 'save' );
		if ( ! $form instanceof \WPCF7_ContactForm || ! $form->id() ) {
			throw new ToolError( 'Contact Form 7 did not save the form.' );
		}
		if ( $id <= 0 ) {
			AuditLog::target( 'cf7_form', (string) $form->id() );
		}
		return array(
			'id'            => (int) $form->id(),
			'title'         => (string) $form->title(),
			'created'       => $id <= 0,
			'config_errors' => self::config_errors( $form ),
		);
	}

	/**
	 * Change the mails (confirm-first when routing changes).
	 *
	 * @param \WPCF7_ContactForm   $form  Form.
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When nothing was asked for or CF7 does not save.
	 */
	private static function mail( \WPCF7_ContactForm $form, array $input ): array {
		if ( ! isset( $input['mail'] ) && ! isset( $input['mail_2'] ) ) {
			throw new ToolError( 'Give mail and/or mail_2. Nothing was changed.' );
		}
		$impact = self::routing_changes( $form, $input );
		$out    = array(
			'id'     => (int) $form->id(),
			'title'  => (string) $form->title(),
			'done'   => true,
			'impact' => $impact,
		);
		if ( $impact && empty( $input['confirm'] ) ) {
			$out['done']          = false;
			$out['notifications'] = Cf7Adapter::mails( $form );
			return $out;
		}
		$mails = self::patched_mails( $form, $input );
		$data  = array( 'id' => (int) $form->id() );
		foreach ( $mails as $name => $mail ) {
			if ( isset( $input[ $name ] ) ) {
				$data[ $name ] = array_intersect_key( $mail, array_flip( array_merge( self::MAIL_KEYS, array( 'active' ) ) ) );
			}
		}
		$saved = wpcf7_save_contact_form( $data, 'save' );
		if ( ! $saved instanceof \WPCF7_ContactForm ) {
			throw new ToolError( 'Contact Form 7 did not save the mail settings.' );
		}
		$out['notifications'] = Cf7Adapter::mails( $saved );
		$out['config_errors'] = self::config_errors( $saved );
		return $out;
	}
}
