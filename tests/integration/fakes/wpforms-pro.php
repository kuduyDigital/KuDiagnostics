<?php
/**
 * A stand-in for the entry storage of WPForms Pro (paid, no licensed copy available), for use with the real WPForms
 * Lite: it registers "entry" and "entry_meta" handlers in WPForms' own registry with the method names Lite itself
 * calls on Pro (get, get_entries, update, add, get_meta, delete), backed by one option. Loaded as a must-use plugin
 * when the runner is started with KUDUY_FAKES=wpforms-pro. It checks KuDiagnostics' side of the contract, not WPForms
 * Pro: the row columns (viewed, starred, status, fields, ip_address, user_agent, date) are assumptions.
 *
 * @package Kuduy\Mcp
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting -- test fake.

/**
 * Rows in an option.
 */
class Kuduy_Fake_Wpforms_Store {

	public static function all( string $kind ): array {
		$data = get_option( 'kuduy_fake_wpforms_' . $kind, array() );
		return is_array( $data ) ? $data : array();
	}

	public static function save( string $kind, array $rows ): void {
		update_option( 'kuduy_fake_wpforms_' . $kind, $rows, false );
	}

	public static function next( string $kind ): int {
		$rows = self::all( $kind );
		return $rows ? max( array_keys( $rows ) ) + 1 : 1;
	}
}

/**
 * Entry handler.
 */
class Kuduy_Fake_Wpforms_Entry {

	public function add( $data, $type = '' ) {
		$rows        = Kuduy_Fake_Wpforms_Store::all( 'entries' );
		$id          = Kuduy_Fake_Wpforms_Store::next( 'entries' );
		$rows[ $id ] = (array) $data + array(
			'entry_id'   => $id,
			'status'     => '',
			'viewed'     => 0,
			'starred'    => 0,
			'date'       => gmdate( 'Y-m-d H:i:s' ),
			'ip_address' => '',
			'user_agent' => '',
			'meta'       => '',
		);
		Kuduy_Fake_Wpforms_Store::save( 'entries', $rows );
		return $id;
	}

	public function get( $id, $args = array() ) {
		$row = Kuduy_Fake_Wpforms_Store::all( 'entries' )[ (int) $id ] ?? null;
		return is_array( $row ) ? (object) $row : null;
	}

	public function get_entries( $args = array(), $count = false ) {
		$out = array();
		foreach ( Kuduy_Fake_Wpforms_Store::all( 'entries' ) as $row ) {
			if ( isset( $args['form_id'] ) && (int) $row['form_id'] !== (int) $args['form_id'] ) {
				continue;
			}
			if ( isset( $args['status'] ) && (string) $row['status'] !== (string) $args['status'] ) {
				continue;
			}
			$out[] = (object) $row;
		}
		if ( $count ) {
			return count( $out );
		}
		usort( $out, static fn( $a, $b ) => $b->entry_id <=> $a->entry_id );
		return array_slice( $out, (int) ( $args['offset'] ?? 0 ), (int) ( $args['number'] ?? 30 ) );
	}

	public function update( $row_id, $data = array(), $where = '', $type = '' ) {
		$rows = Kuduy_Fake_Wpforms_Store::all( 'entries' );
		if ( ! isset( $rows[ (int) $row_id ] ) ) {
			return false;
		}
		$rows[ (int) $row_id ] = (array) $data + $rows[ (int) $row_id ];
		Kuduy_Fake_Wpforms_Store::save( 'entries', $rows );
		return true;
	}
}

/**
 * Entry meta handler (notes).
 */
class Kuduy_Fake_Wpforms_Entry_Meta {

	public function add( $data, $type = '' ) {
		$rows        = Kuduy_Fake_Wpforms_Store::all( 'meta' );
		$id          = Kuduy_Fake_Wpforms_Store::next( 'meta' );
		$rows[ $id ] = (array) $data + array(
			'id'   => $id,
			'date' => gmdate( 'Y-m-d H:i:s' ),
		);
		Kuduy_Fake_Wpforms_Store::save( 'meta', $rows );
		return $id;
	}

	public function get_meta( $args = array(), $count = false ) {
		$out = array();
		foreach ( array_reverse( Kuduy_Fake_Wpforms_Store::all( 'meta' ), true ) as $row ) {
			if ( (int) $row['entry_id'] === (int) ( $args['entry_id'] ?? 0 ) && ( ! isset( $args['type'] ) || $row['type'] === $args['type'] ) ) {
				$out[] = (object) $row;
			}
		}
		return $count ? count( $out ) : $out;
	}

	public function delete( $row_id = 0 ) {
		$rows = Kuduy_Fake_Wpforms_Store::all( 'meta' );
		unset( $rows[ (int) $row_id ] );
		Kuduy_Fake_Wpforms_Store::save( 'meta', $rows );
		return true;
	}
}

add_action(
	'wpforms_loaded',
	static function () {
		wpforms()->register_instance( 'entry', new Kuduy_Fake_Wpforms_Entry() );
		wpforms()->register_instance( 'entry_meta', new Kuduy_Fake_Wpforms_Entry_Meta() );
	},
	20
);
