<?php
/**
 * Tools: kuduy-forms-entries, kuduy-forms-entry-get, kuduy-forms-entry-update, kuduy-forms-entry-note.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Forms;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Audit\MasksAuditArgs;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;
use Kuduy\Mcp\Modules\Core\Packages;

/**
 * Form entries of every supported plugin (D15 A). Inbox actions only, each with undo; permanent delete is not offered
 * (D16 A). Visitors' personal data is masked by field kind unless include_pii is asked for and the plugin lets the user
 * edit that entry (D17 A). A change the plugin would report outside the site (Flamingo → Akismet) is confirm-first.
 */
final class FormEntries implements Tool, Snapshottable, MasksAuditArgs {

	/**
	 * Mode.
	 *
	 * @param string $mode entries | entry-get | entry-update | entry-note.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-forms-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'entries'      => __( 'List form entries', 'kuduy-mcp' ),
			'entry-get'    => __( 'Read a form entry', 'kuduy-mcp' ),
			'entry-update' => __( 'Update a form entry', 'kuduy-mcp' ),
			default        => __( 'Add a note to a form entry', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		$pii = ' Visitors\' personal data (email, phone, name, address, uploaded files, IP address, browser) is masked unless include_pii is true and the forms plugin lets your account edit the entry; password fields are never shown.';
		return match ( $this->mode ) {
			'entries'      => 'Lists entries (submissions) of one form, newest first: id, date, status (active, spam, trash), read and starred where the plugin has them, field values. Filter by status (default active) and search text; page and per_page (max 100). Contact Form 7 entries come from Flamingo; WPForms Lite keeps none on the site.' . $pii,
			'entry-get'    => 'Returns one form entry with every field and its internal notes.' . $pii,
			'entry-update' => 'Changes one entry like the plugin\'s inbox does: read (true/false), starred (true/false), status (active, spam, trash; active also restores from spam or trash). Permanent delete is not offered. If the plugin would report the change outside the site (Flamingo sends the message to Akismet when Akismet checked it), the first call only reports it (done: false): ask the user, then call again with confirm: true. Snapshot first; undo with kuduy-snapshot-restore (an undo never reports to Akismet).',
			default        => 'Adds an internal note to one entry (Fluent Forms). Notes are for site staff and are not emailed. Undo with kuduy-snapshot-restore removes the note.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$pii                       = array(
			'type'        => 'boolean',
			'default'     => false,
			'description' => 'Show visitors\' personal data in full (needs permission to edit the entries).',
		);
		$entry                     = array(
			'plugin'   => Forms::plugin_input(),
			'entry_id' => Forms::id_input(),
		);
		[ $properties, $required ] = match ( $this->mode ) {
			'entries'      => array(
				array(
					'plugin'      => Forms::plugin_input(),
					'form_id'     => Forms::id_input(),
					'status'      => array(
						'type'    => 'string',
						'enum'    => array( 'active', 'spam', 'trash', 'all' ),
						'default' => 'active',
					),
					'search'      => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'page'        => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'per_page'    => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
					),
					'include_pii' => $pii,
				),
				array( 'plugin', 'form_id' ),
			),
			'entry-get'    => array( $entry + array( 'include_pii' => $pii ), array( 'plugin', 'entry_id' ) ),
			'entry-update' => array(
				$entry + array(
					'read'    => array( 'type' => 'boolean' ),
					'starred' => array( 'type' => 'boolean' ),
					'status'  => array(
						'type' => 'string',
						'enum' => Forms::STATUSES,
					),
					'confirm' => Packages::confirm_input(),
				),
				array( 'plugin', 'entry_id' ),
			),
			default        => array(
				$entry + array(
					'note' => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 5000,
					),
				),
				array( 'plugin', 'entry_id', 'note' ),
			),
		};//end match
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
		$entry = array( 'type' => 'object' );
		return match ( $this->mode ) {
			'entries'      => array(
				'type'       => 'object',
				'properties' => array(
					'entries' => array(
						'type'  => 'array',
						'items' => $entry,
					),
					'total'   => array( 'type' => 'integer' ),
					'page'    => array( 'type' => 'integer' ),
				),
				'required'   => array( 'entries', 'total', 'page' ),
			),
			'entry-get'    => $entry + array(
				'properties' => array(
					'id'     => array( 'type' => 'integer' ),
					'fields' => array( 'type' => 'array' ),
				),
				'required'   => array( 'id', 'fields' ),
			),
			'entry-update' => array(
				'type'       => 'object',
				'properties' => array(
					'done'         => array(
						'type'        => 'boolean',
						'description' => 'False when only the impact was reported (confirm needed).',
					),
					'impact'       => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'entry'        => $entry,
					'_snapshot_id' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'done', 'impact', 'entry' ),
			),
			default        => array(
				'type'       => 'object',
				'properties' => array(
					'note_id'      => array( 'type' => 'integer' ),
					'entry_id'     => array( 'type' => 'integer' ),
					'_snapshot_id' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'note_id', 'entry_id' ),
			),
		};//end match
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		if ( in_array( $this->mode, array( 'entries', 'entry-get' ), true ) ) {
			return Content::read_only();
		}
		return array(
			'readOnlyHint'    => false,
			// Both change the entry and keep a snapshot for undo (the executor snapshots destructive tools only).
			'destructiveHint' => true,
			'idempotentHint'  => 'entry-update' === $this->mode,
			// Flamingo may report spam changes to Akismet (confirm-first).
			'openWorldHint'   => 'entry-update' === $this->mode,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return in_array( $this->mode, array( 'entries', 'entry-get' ), true ) ? 'kuduy:read' : 'kuduy:write';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		// The floor; each adapter applies its plugin's own permission per form and entry.
		return 'read';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return Forms::available();
	}

	/**
	 * Note text stays out of audit rows and snapshot input (it may name visitors).
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	public function audit_args( array $args ): array {
		if ( 'entry-note' === $this->mode && isset( $args['note'] ) ) {
			$args['note'] = Mask::MASK;
		}
		return $args;
	}

	/**
	 * Changes asked for.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private static function changes( array $input ): array {
		return array_intersect_key( $input, array_flip( array( 'read', 'starred', 'status' ) ) );
	}

	/**
	 * Refuse a change the user may not make.
	 *
	 * @param FormsAdapter         $adapter  Adapter.
	 * @param int                  $entry_id Entry id.
	 * @param array<string, mixed> $change   Change.
	 * @throws ToolError When nothing was asked for or the plugin does not allow it.
	 */
	private static function assert_change( FormsAdapter $adapter, int $entry_id, array $change ): void {
		if ( ! $change ) {
			throw new ToolError( 'Give at least one of read, starred or status. Nothing was changed.' );
		}
		$from    = (string) $adapter->state( $entry_id )['status'];
		$to      = (string) ( $change['status'] ?? $from );
		$actions = array();
		if ( isset( $change['read'] ) || isset( $change['starred'] ) ) {
			$actions[] = 'edit';
		}
		if ( in_array( 'trash', array( $from, $to ), true ) && $from !== $to ) {
			$actions[] = 'trash';
		}
		if ( in_array( 'spam', array( $from, $to ), true ) && $from !== $to ) {
			$actions[] = 'spam';
		}
		foreach ( $actions ? $actions : array( 'edit' ) as $action ) {
			if ( ! $adapter->allowed( $action, $entry_id ) ) {
				throw new ToolError( esc_html( $adapter->name() ) . ' does not allow your account to change this entry (' . esc_html( $action ) . '). Nothing was changed.' );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the change is refused (no snapshot is kept).
	 */
	public function snapshot( array $input ): ?array {
		if ( ! in_array( $this->mode, array( 'entry-update', 'entry-note' ), true ) ) {
			return null;
		}
		$adapter  = Forms::adapter( (string) ( $input['plugin'] ?? '' ) );
		$entry_id = (int) ( $input['entry_id'] ?? 0 );
		if ( 'entry-note' === $this->mode ) {
			if ( ! $adapter->allowed( 'note', $entry_id ) ) {
				// Execute explains the refusal.
				return null;
			}
			$ids = array_column( (array) ( $adapter->entry( $entry_id )['notes'] ?? array() ), 'id' );
			return array(
				'object_type' => $adapter->slug() . '_entry',
				'object_id'   => (string) $entry_id,
				'payload'     => array(
					'plugin'   => $adapter->slug(),
					'entry_id' => $entry_id,
					'notes'    => array_map( 'intval', $ids ),
				),
			);
		}
		$change = self::changes( $input );
		self::assert_change( $adapter, $entry_id, $change );
		if ( $adapter->external_effects( $entry_id, $change ) && empty( $input['confirm'] ) ) {
			return null;
		}
		return array(
			'object_type' => $adapter->slug() . '_entry',
			'object_id'   => (string) $entry_id,
			'payload'     => array(
				'plugin'   => $adapter->slug(),
				'entry_id' => $entry_id,
				'state'    => $adapter->state( $entry_id ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the plugin or entry is gone.
	 */
	public function restore( array $payload ): void {
		$adapter  = Forms::adapter( (string) ( $payload['plugin'] ?? '' ) );
		$entry_id = (int) ( $payload['entry_id'] ?? 0 );
		if ( isset( $payload['state'] ) ) {
			$adapter->change( $entry_id, (array) $payload['state'], true );
			return;
		}
		$before = array_map( 'intval', (array) ( $payload['notes'] ?? array() ) );
		foreach ( (array) ( $adapter->entry( $entry_id )['notes'] ?? array() ) as $note ) {
			if ( ! in_array( (int) $note['id'], $before, true ) ) {
				$adapter->delete_note( $entry_id, (int) $note['id'] );
			}
		}
	}

	/**
	 * Full data only when asked for and allowed for this entry.
	 *
	 * @param FormsAdapter $adapter  Adapter.
	 * @param int          $entry_id Entry id.
	 * @param bool         $asked    include_pii.
	 * @throws ToolError When asked for but not allowed.
	 */
	private static function full( FormsAdapter $adapter, int $entry_id, bool $asked ): bool {
		if ( $asked && ! $adapter->allowed( 'edit', $entry_id ) ) {
			throw new ToolError( 'Full personal data needs permission to edit this entry in ' . esc_html( $adapter->name() ) . '. Call again without include_pii to see masked values.' );
		}
		return $asked;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the plugin, form or entry is missing or the plugin refuses.
	 */
	public function execute( array $input ): array {
		$adapter = Forms::adapter( (string) $input['plugin'] );
		$asked   = ! empty( $input['include_pii'] );
		if ( 'entries' === $this->mode ) {
			AuditLog::target( $adapter->slug() . '_form', (string) (int) $input['form_id'] );
			$result  = $adapter->entries( (int) $input['form_id'], $input );
			$entries = array();
			foreach ( $result['entries'] as $entry ) {
				$entries[] = Mask::entry( $entry, self::full( $adapter, (int) $entry['id'], $asked ) );
			}
			return array(
				'entries' => $entries,
				'total'   => $result['total'],
				'page'    => Forms::paging( $input )[0],
			);
		}
		$entry_id = (int) $input['entry_id'];
		AuditLog::target( $adapter->slug() . '_entry', (string) $entry_id );
		if ( 'entry-get' === $this->mode ) {
			$entry = $adapter->entry( $entry_id );
			return Mask::entry( $entry, self::full( $adapter, $entry_id, $asked ) );
		}
		if ( 'entry-note' === $this->mode ) {
			if ( ! $adapter->allowed( 'note', $entry_id ) ) {
				// A missing entry gets the plugin's own message.
				$adapter->entry( $entry_id );
				throw new ToolError(
					$adapter instanceof Cf7Adapter
						? 'Flamingo messages have no notes. Nothing was changed.'
						: esc_html( $adapter->name() ) . ' does not allow your account to add notes to this entry.'
				);
			}
			return array(
				'note_id'  => $adapter->add_note( $entry_id, (string) $input['note'] ),
				'entry_id' => $entry_id,
			);
		}
		$change = self::changes( $input );
		self::assert_change( $adapter, $entry_id, $change );
		$impact = $adapter->external_effects( $entry_id, $change );
		if ( $impact && empty( $input['confirm'] ) ) {
			return array(
				'done'   => false,
				'impact' => $impact,
				'entry'  => Mask::entry( $adapter->entry( $entry_id ), false ),
			);
		}
		$adapter->change( $entry_id, $change );
		return array(
			'done'   => true,
			'impact' => $impact,
			'entry'  => Mask::entry( $adapter->entry( $entry_id ), false ),
		);
	}
}
