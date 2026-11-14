<?php
/**
 * Ninja Forms through its own model factory.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Forms;

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Forms, fields and actions through Ninja_Forms()->form( $id ) (get_forms, get_fields, get_actions); submissions are
 * posts of type nf_sub, read with the factory's get_sub() and trashed with the submission model's trash() (source of
 * Ninja Forms 3.16.1, RESEARCH.md §10b). Ninja Forms has no read, starred or spam flag and no entry notes. Its admin
 * screens are guarded by filterable capabilities that default to manage_options; the same filters are applied here.
 */
final class NinjaAdapter implements FormsAdapter {

	/**
	 * Entry kinds per Ninja Forms field type.
	 */
	private const KINDS = array(
		'email'           => 'email',
		'phone'           => 'phone',
		'firstname'       => 'name',
		'lastname'        => 'name',
		'address'         => 'address',
		'city'            => 'address',
		'zip'             => 'address',
		'file_upload'     => 'file',
		'password'        => 'password',
		'passwordconfirm' => 'password',
	);

	/**
	 * Field types that hold no submitted value.
	 */
	private const NO_VALUE = array( 'submit', 'html', 'hr', 'divider', 'recaptcha', 'recaptcha_v3', 'spam', 'note', 'unknown' );

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'ninjaforms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'Ninja Forms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function active(): bool {
		return function_exists( 'Ninja_Forms' ) && class_exists( 'NF_Database_Models_Submission' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function version(): string {
		return defined( 'Ninja_Forms::VERSION' ) ? (string) constant( 'Ninja_Forms::VERSION' ) : '';
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
	 * Whether the user may open Ninja Forms' forms (true) or submissions (false) screens.
	 *
	 * @param bool $forms Forms, else submissions.
	 */
	private static function can( bool $forms ): bool {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Ninja Forms' own filters.
		$cap = $forms ? apply_filters( 'ninja_forms_admin_parent_menu_capabilities', 'manage_options' ) : apply_filters( 'ninja_forms_admin_submissions_capabilities', 'manage_options' );
		return current_user_can( is_string( $cap ) && '' !== $cap ? $cap : 'manage_options' );
	}

	/**
	 * Form models by id.
	 *
	 * @return array<int, object>
	 */
	private static function models(): array {
		$out = array();
		foreach ( (array) Ninja_Forms()->form()->get_forms() as $form ) {
			if ( is_object( $form ) ) {
				$out[ (int) $form->get_id() ] = $form;
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * A form model, or a ToolError.
	 *
	 * @param int $id Form id.
	 * @throws ToolError When there is none.
	 */
	private static function find( int $id ): object {
		$form = self::models()[ $id ] ?? null;
		if ( null === $form ) {
			throw new ToolError( 'No Ninja Forms form with id ' . (int) $id . '.' );
		}
		return $form;
	}

	/**
	 * Submission post ids of a form.
	 *
	 * @param int                  $form_id Form id.
	 * @param list<string>         $status  Post statuses.
	 * @param array<string, mixed> $extra   More query arguments.
	 */
	private static function query( int $form_id, array $status, array $extra = array() ): \WP_Query {
		return new \WP_Query(
			$extra + array(
				'post_type'      => 'nf_sub',
				'post_status'    => $status,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'posts_per_page' => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Ninja Forms links a submission to its form by this meta.
				'meta_query'     => array(
					array(
						'key'   => '_form_id',
						'value' => $form_id,
					),
				),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function forms(): array {
		if ( ! self::can( true ) ) {
			return array();
		}
		return array_values( array_map( array( $this, 'summary' ), self::models() ) );
	}

	/**
	 * Summary row.
	 *
	 * @param object $form Form model.
	 * @return array<string, mixed>
	 */
	private function summary( object $form ): array {
		$id = (int) $form->get_id();
		return array(
			'plugin'    => $this->slug(),
			'id'        => $id,
			'title'     => (string) $form->get_setting( 'title' ),
			'entries'   => self::can( false ) ? (int) self::query( $id, array( 'publish' ) )->found_posts : null,
			'shortcode' => '[ninja_form id="' . $id . '"]',
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
		foreach ( (array) Ninja_Forms()->form( $form_id )->get_fields() as $field ) {
			if ( ! is_object( $field ) ) {
				continue;
			}
			$type = (string) $field->get_setting( 'type' );
			if ( in_array( $type, self::NO_VALUE, true ) ) {
				continue;
			}
			$key     = (string) $field->get_setting( 'key' );
			$label   = (string) $field->get_setting( 'label' );
			$item    = array(
				'id'       => (int) $field->get_id(),
				'name'     => '' !== $key ? $key : (string) $field->get_id(),
				'label'    => '' !== $label ? $label : $key,
				'type'     => $type,
				'required' => ! empty( $field->get_setting( 'required' ) ),
				'kind'     => self::KINDS[ $type ] ?? Mask::kind_from_name( $label . ' ' . $key ),
			);
			$options = $field->get_setting( 'options' );
			if ( is_array( $options ) && $options ) {
				$item['choices'] = array_values( array_map( static fn( $o ) => (string) ( is_array( $o ) ? ( $o['value'] ?? $o['label'] ?? '' ) : $o ), $options ) );
			}
			$out[] = $item;
		}//end foreach
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Form id.
	 * @throws ToolError When the form is missing or the user may not manage forms.
	 */
	public function form( int $id ): array {
		$form = self::find( $id );
		if ( ! self::can( true ) ) {
			throw new ToolError( 'Ninja Forms does not allow your account to manage forms.' );
		}
		$notifications = array();
		$messages      = array();
		foreach ( (array) Ninja_Forms()->form( $id )->get_actions() as $action ) {
			if ( ! is_object( $action ) ) {
				continue;
			}
			$type = (string) $action->get_setting( 'type' );
			if ( 'email' === $type ) {
				$notifications[] = array(
					'name'      => (string) $action->get_setting( 'label' ),
					'active'    => ! empty( $action->get_setting( 'active' ) ),
					'recipient' => (string) $action->get_setting( 'to' ),
					'sender'    => trim( (string) $action->get_setting( 'from_name' ) . ' <' . (string) $action->get_setting( 'from_address' ) . '>', ' <>' ),
					'reply_to'  => (string) $action->get_setting( 'reply_to' ),
					'cc'        => (string) $action->get_setting( 'cc' ),
					'bcc'       => (string) $action->get_setting( 'bcc' ),
					'subject'   => (string) $action->get_setting( 'email_subject' ),
					'body'      => (string) $action->get_setting( 'email_message' ),
				);
			} elseif ( 'successmessage' === $type ) {
				$messages['success'] = wp_strip_all_tags( (string) $action->get_setting( 'success_msg' ) );
			} elseif ( 'redirect' === $type ) {
				$messages['redirect_to'] = (string) $action->get_setting( 'redirect_url' );
			}
		}//end foreach
		return $this->summary( $form ) + array(
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
			'messages'      => (object) $messages,
			'notifications' => $notifications,
		);
	}

	/**
	 * A submission post, or a ToolError.
	 *
	 * @param int $id Entry id.
	 * @throws ToolError When there is none.
	 */
	private static function sub_post( int $id ): \WP_Post {
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof \WP_Post || 'nf_sub' !== $post->post_type ) {
			throw new ToolError( 'No Ninja Forms submission with id ' . (int) $id . '.' );
		}
		return $post;
	}

	/**
	 * One submission in the shared entry shape.
	 *
	 * @param \WP_Post                   $post   Submission post.
	 * @param int                        $form   Form id.
	 * @param list<array<string, mixed>> $fields Form fields.
	 * @return array<string, mixed>
	 */
	private static function normalize( \WP_Post $post, int $form, array $fields ): array {
		$sub = Ninja_Forms()->form( $form )->get_sub( $post->ID );
		$out = array();
		foreach ( $fields as $field ) {
			$value = $sub->get_field_value( $field['id'] );
			if ( '' === $value || null === $value || false === $value || array() === $value ) {
				continue;
			}
			$out[] = array(
				'name'  => $field['name'],
				'label' => $field['label'],
				'kind'  => $field['kind'],
				'value' => $value,
			);
		}
		return array(
			'plugin'  => 'ninjaforms',
			'id'      => $post->ID,
			'form_id' => $form,
			'date'    => (string) $post->post_date_gmt,
			'status'  => 'trash' === $post->post_status ? 'trash' : 'active',
			'read'    => null,
			'starred' => null,
			'subject' => null,
			'sender'  => null,
			'fields'  => $out,
			'meta'    => array(
				'ip'         => '',
				'user_agent' => '',
				'source_url' => '',
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $form_id Form id.
	 * @param array<string, mixed> $query   Query.
	 * @throws ToolError When the form is missing or the user may not read submissions.
	 */
	public function entries( int $form_id, array $query ): array {
		self::find( $form_id );
		if ( ! self::can( false ) ) {
			throw new ToolError( 'Ninja Forms does not allow your account to read submissions.' );
		}
		$status = (string) ( $query['status'] ?? 'active' );
		if ( 'spam' === $status ) {
			return array(
				'entries' => array(),
				'total'   => 0,
			);
		}
		$statuses = match ( $status ) {
			'trash' => array( 'trash' ),
			'all'   => array( 'publish', 'trash' ),
			default => array( 'publish' ),
		};
		[ $page, $per ] = Forms::paging( $query );
		$extra          = array(
			'posts_per_page' => $per,
			'paged'          => $page,
		);
		$search         = (string) ( $query['search'] ?? '' );
		if ( '' !== $search ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- submitted values are post meta.
			$extra['meta_query'] = array(
				array(
					'key'   => '_form_id',
					'value' => $form_id,
				),
				array(
					'value'   => $search,
					'compare' => 'LIKE',
				),
			);
		}
		$found   = self::query( $form_id, $statuses, $extra );
		$fields  = self::fields( $form_id );
		$entries = array();
		foreach ( $found->posts as $id ) {
			$post = get_post( (int) $id );
			if ( $post instanceof \WP_Post ) {
				$entries[] = self::normalize( $post, $form_id, $fields );
			}
		}
		return array(
			'entries' => $entries,
			'total'   => (int) $found->found_posts,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $entry_id Entry id.
	 * @throws ToolError When the submission is missing or the user may not read it.
	 */
	public function entry( int $entry_id ): array {
		$post = self::sub_post( $entry_id );
		if ( ! self::can( false ) ) {
			throw new ToolError( 'Ninja Forms does not allow your account to read this submission.' );
		}
		$form = (int) get_post_meta( $post->ID, '_form_id', true );
		return self::normalize( $post, $form, self::fields( $form ) ) + array( 'notes' => array() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $action   read | edit | trash | spam | note.
	 * @param int    $entry_id Entry id.
	 */
	public function allowed( string $action, int $entry_id ): bool {
		$post = $entry_id > 0 ? get_post( $entry_id ) : null;
		return $post instanceof \WP_Post && 'nf_sub' === $post->post_type && self::can( false );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $entry_id Entry id.
	 */
	public function state( int $entry_id ): array {
		$post = self::sub_post( $entry_id );
		return array(
			'status'  => 'trash' === $post->post_status ? 'trash' : 'active',
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
	 * @param array<string, mixed> $change   Change or saved state.
	 * @param bool                 $restore  Undo.
	 * @throws ToolError When Ninja Forms has no such flag.
	 */
	public function change( int $entry_id, array $change, bool $restore = false ): void {
		$post = self::sub_post( $entry_id );
		if ( ! $restore && ( isset( $change['read'] ) || isset( $change['starred'] ) ) ) {
			throw new ToolError( 'Ninja Forms submissions have no read or starred flag. Nothing was changed.' );
		}
		$to = (string) ( $change['status'] ?? '' );
		if ( 'spam' === $to ) {
			throw new ToolError( 'Ninja Forms has no spam folder for submissions; move it to the trash instead. Nothing was changed.' );
		}
		$now = 'trash' === $post->post_status ? 'trash' : 'active';
		if ( '' === $to || $to === $now ) {
			return;
		}
		if ( 'trash' === $to ) {
			Ninja_Forms()->form( (int) get_post_meta( $entry_id, '_form_id', true ) )->get_sub( $entry_id )->trash();
			return;
		}
		// WordPress untrashes to "draft" unless told otherwise; a submission goes back to what it was.
		$keep = static fn( $status, $id, $previous ) => $previous;
		add_filter( 'wp_untrash_post_status', $keep, 10, 3 );
		wp_untrash_post( $entry_id );
		remove_filter( 'wp_untrash_post_status', $keep, 10 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $entry_id Entry id.
	 * @param string $text     Note.
	 * @throws ToolError Always: Ninja Forms has no entry notes.
	 */
	public function add_note( int $entry_id, string $text ): int {
		throw new ToolError( 'Ninja Forms has no notes on submissions. Nothing was changed.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $entry_id Entry id.
	 * @param int $note_id  Note id.
	 */
	public function delete_note( int $entry_id, int $note_id ): void {
		// No notes in Ninja Forms.
	}
}
