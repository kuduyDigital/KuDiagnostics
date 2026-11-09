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
 * WPForms Lite stores no entries on the site; WPForms Pro entries are not supported yet. Only known settings are
 * returned (no provider or payment settings).
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
	 * Whether this is WPForms Pro.
	 */
	private static function pro(): bool {
		return (bool) wpforms()->is_pro();
	}

	/**
	 * {@inheritDoc}
	 */
	public function entries_store(): ?string {
		return self::pro() ? 'plugin' : null;
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
			'entries'   => null,
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
	 * Refuse: no entries in this adapter.
	 *
	 * @throws ToolError Always.
	 */
	private function no_entries(): never {
		if ( self::pro() ) {
			throw new ToolError( 'WPForms Pro entries are not supported by KUDUY yet. Nothing was read or changed.' );
		}
		Forms::no_entries( $this, 'WPForms Lite does not save submissions on the site (it only emails them).' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $form_id Form id.
	 * @param array<string, mixed> $query   Query.
	 * @throws ToolError Always.
	 */
	public function entries( int $form_id, array $query ): array {
		$this->no_entries();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $entry_id Entry id.
	 * @throws ToolError Always.
	 */
	public function entry( int $entry_id ): array {
		$this->no_entries();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $action   Action.
	 * @param int    $entry_id Entry id.
	 */
	public function allowed( string $action, int $entry_id ): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $entry_id Entry id.
	 * @throws ToolError Always.
	 */
	public function state( int $entry_id ): array {
		$this->no_entries();
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
	 * @throws ToolError Always.
	 */
	public function change( int $entry_id, array $change, bool $restore = false ): void {
		$this->no_entries();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $entry_id Entry id.
	 * @param string $text     Note.
	 * @throws ToolError Always.
	 */
	public function add_note( int $entry_id, string $text ): int {
		$this->no_entries();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $entry_id Entry id.
	 * @param int $note_id  Note id.
	 */
	public function delete_note( int $entry_id, int $note_id ): void {
		// No entries, so no notes to remove.
	}
}
