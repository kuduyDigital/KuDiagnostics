<?php
/**
 * One forms plugin behind the shared kuduy-forms-* tools (decision D15 A).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Forms;

use Kuduy\Mcp\Mcp\ToolError;

/**
 * An adapter reads and changes a forms plugin through its own public API and checks the plugin's own permissions for
 * the current user. Results use one shape across plugins; integration keys are never part of them. Entry values come
 * back unmasked with a field kind; the tools mask them (Mask, D17 A).
 *
 * Entry shape: plugin, id, form_id, date, status (active | spam | trash), read (bool or null when the plugin has no
 * read flag), starred (bool or null), fields (list of name, label, kind, value), sender (name, email or null),
 * subject (string or null), meta (ip, user_agent, source_url), notes (entry() only: id, author, date, text).
 */
interface FormsAdapter {

	/**
	 * Short id used in tool input (cf7, fluentform, wpforms).
	 */
	public function slug(): string;

	/**
	 * Plugin name.
	 */
	public function name(): string;

	/**
	 * Whether the plugin is loaded.
	 */
	public function active(): bool;

	/**
	 * Plugin version.
	 */
	public function version(): string;

	/**
	 * Where entries are stored: "flamingo", "plugin", or null when the site keeps none.
	 */
	public function entries_store(): ?string;

	/**
	 * Whether the plugin's own abilities are switched on, or null when it has none.
	 */
	public function abilities(): ?bool;

	/**
	 * Forms the current user may read: plugin, id, title, entries (count, or null when the user may not read entries
	 * or none are stored), shortcode.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function forms(): array;

	/**
	 * One form the current user may read: fields, messages, notifications (mail routing), settings.
	 *
	 * @param int $id Form id.
	 * @return array<string, mixed>
	 * @throws ToolError When the form is missing or the user may not read it.
	 */
	public function form( int $id ): array;

	/**
	 * Entries of one form, newest first.
	 *
	 * @param int                  $form_id Form id.
	 * @param array<string, mixed> $query   status (active | spam | trash | all), search, page, per_page.
	 * @return array{entries: list<array<string, mixed>>, total: int}
	 * @throws ToolError When the site stores no entries, the form is missing or the user may not read its entries.
	 */
	public function entries( int $form_id, array $query ): array;

	/**
	 * One entry with its notes.
	 *
	 * @param int $entry_id Entry id.
	 * @return array<string, mixed>
	 * @throws ToolError When the entry is missing or the user may not read it.
	 */
	public function entry( int $entry_id ): array;

	/**
	 * Whether the current user may do something with an entry.
	 *
	 * @param string $action read | edit | trash | spam | note.
	 * @param int    $entry_id Entry id.
	 */
	public function allowed( string $action, int $entry_id ): bool;

	/**
	 * State that kuduy-forms-entry-update changes, for the snapshot: read, starred, status, plus what the adapter
	 * needs to put it back without side effects.
	 *
	 * @param int $entry_id Entry id.
	 * @return array<string, mixed>
	 * @throws ToolError When the entry is missing.
	 */
	public function state( int $entry_id ): array;

	/**
	 * Effects outside the site a change would have (for confirm-first), e.g. Flamingo reporting to Akismet.
	 *
	 * @param int                  $entry_id Entry id.
	 * @param array<string, mixed> $change   read, starred, status.
	 * @return list<string>
	 */
	public function external_effects( int $entry_id, array $change ): array;

	/**
	 * Apply a change (read, starred, status). With $restore the saved state() is put back without side effects.
	 *
	 * @param int                  $entry_id Entry id.
	 * @param array<string, mixed> $change   Change, or a saved state() when restoring.
	 * @param bool                 $restore  Whether this is an undo.
	 * @throws ToolError When the plugin cannot make the change.
	 */
	public function change( int $entry_id, array $change, bool $restore = false ): void;

	/**
	 * Add an internal note; returns its id.
	 *
	 * @param int    $entry_id Entry id.
	 * @param string $text     Note.
	 * @throws ToolError When the plugin has no notes.
	 */
	public function add_note( int $entry_id, string $text ): int;

	/**
	 * Remove a note added by add_note() (undo).
	 *
	 * @param int $entry_id Entry id.
	 * @param int $note_id  Note id.
	 */
	public function delete_note( int $entry_id, int $note_id ): void;
}
