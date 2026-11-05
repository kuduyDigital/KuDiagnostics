<?php
/**
 * Snapshot storage and restore (test matrix F10).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Execution;

use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Mcp\ToolRegistry;
use Kuduy\Mcp\OAuth\Scopes;
use Kuduy\Mcp\OAuth\TokenAuth;

/**
 * A snapshot restores once, by its owner or a site manager, through the tool that made it.
 */
final class Snapshots {

	/**
	 * Save a snapshot; returns its id.
	 *
	 * @param string                                                                    $tool Tool name.
	 * @param array{object_type:string, object_id:string, payload:array<string, mixed>} $snap  Snapshot.
	 * @param array<string, mixed>                                                      $input Validated tool input, so restore can re-check the capability for the same object.
	 */
	public static function store( string $tool, array $snap, array $input = array() ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		$wpdb->insert(
			$wpdb->prefix . 'kuduy_snapshots',
			array(
				'ts'          => gmdate( 'Y-m-d H:i:s' ),
				'user_id'     => get_current_user_id(),
				'tool'        => $tool,
				'object_type' => mb_substr( $snap['object_type'], 0, 32 ),
				'object_id'   => mb_substr( $snap['object_id'], 0, 64 ),
				'payload'     => (string) wp_json_encode(
					array(
						'input' => $input,
						'state' => $snap['payload'],
					)
				),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Restorable snapshots the current user may see (own; all for site managers).
	 *
	 * @param int $limit Rows.
	 * @return list<array<string, mixed>>
	 */
	public static function visible( int $limit = 20 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'kuduy_snapshots';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$rows = current_user_can( 'manage_options' )
			? $wpdb->get_results( $wpdb->prepare( "SELECT id, ts, user_id, tool, object_type, object_id FROM {$table} WHERE restored_at IS NULL ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( "SELECT id, ts, user_id, tool, object_type, object_id FROM {$table} WHERE restored_at IS NULL AND user_id = %d ORDER BY id DESC LIMIT %d", get_current_user_id(), $limit ), ARRAY_A );
		// phpcs:enable
		return array_map(
			static fn( array $row ): array => array(
				'id'      => (int) $row['id'],
				'user_id' => (int) $row['user_id'],
			) + $row,
			array_values( (array) $rows )
		);
	}

	/**
	 * Restore a snapshot.
	 *
	 * @param int $id Snapshot id.
	 * @return array<string, mixed> What was restored.
	 * @throws ToolError When it cannot be restored.
	 * @throws \Throwable When the tool's own restore fails (the claim is released first).
	 */
	public static function restore( int $id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'kuduy_snapshots';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			throw new ToolError( 'Snapshot not found.' );
		}
		if ( get_current_user_id() !== (int) $row['user_id'] && ! current_user_can( 'manage_options' ) ) {
			throw new ToolError( 'Only the person who made this change, or a site manager, can restore it.' );
		}
		$tool = ToolRegistry::create()->get( (string) $row['tool'] );
		if ( ! $tool instanceof Snapshottable ) {
			throw new ToolError( 'The tool that made this snapshot is not available, so it cannot be restored.' );
		}
		$saved = json_decode( (string) $row['payload'], true );
		$input = is_array( $saved['input'] ?? null ) ? $saved['input'] : array();
		$state = is_array( $saved['state'] ?? null ) ? $saved['state'] : array();
		// Undo is the original change in reverse: it needs the same OAuth scope and capability as the tool that made it.
		$granted = TokenAuth::scopes();
		if ( null !== $granted && ! Scopes::allows( $granted, $tool->scope() ) ) {
			throw new ToolError( 'This connection was not granted the access level that undoing this change needs.' );
		}
		if ( ! current_user_can( $tool->capability( $input ) ) ) {
			throw new ToolError( 'Your WordPress account is not allowed to make this change.' );
		}
		// Claim first so two simultaneous restores cannot both run; release the claim if restoring fails.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		if ( 1 !== (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET restored_at = %s WHERE id = %d AND restored_at IS NULL", gmdate( 'Y-m-d H:i:s' ), $id ) ) ) {
			throw new ToolError( 'This snapshot was already restored.' );
		}
		try {
			$tool->restore( $state );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
			$wpdb->update( $table, array( 'restored_at' => null ), array( 'id' => $id ) );
			throw $e;
		}
		return array(
			'restored'    => $id,
			'object_type' => (string) $row['object_type'],
			'object_id'   => (string) $row['object_id'],
		);
	}
}
