<?php
/**
 * A stand-in for Gravity Forms in tests (no licensed copy available): the GFAPI methods KuDiagnostics calls, with the
 * signatures and return shapes from docs.gravityforms.com, backed by one option. Loaded as a must-use plugin when the
 * runner is started with KUDUY_FAKES=gravity. Like Gravity Forms without a role editor, administrators get
 * gform_full_access. This checks KuDiagnostics' side of the contract only, not Gravity Forms itself.
 *
 * @package Kuduy\Mcp
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting -- test fake.

/**
 * Version holder, like Gravity's main class.
 */
class GFForms {

	/**
	 * Version.
	 *
	 * @var string
	 */
	public static $version = '2.9.0-fake';
}

/**
 * Documented GFAPI subset over an option.
 */
class GFAPI {

	private const OPTION = 'kuduy_fake_gravity';

	private static function data(): array {
		$data = get_option( self::OPTION, array() );
		return is_array( $data ) ? $data + array(
			'forms'   => array(),
			'entries' => array(),
			'notes'   => array(),
			'next'    => 1,
		) : array();
	}

	private static function save( array $data ): void {
		update_option( self::OPTION, $data, false );
	}

	public static function add_test_form( array $form ): int {
		$data                       = self::data();
		$id                         = $data['next']++;
		$form['id']                 = $id;
		$data['forms'][ $id ]       = $form;
		self::save( $data );
		return $id;
	}

	public static function add_test_entry( array $entry ): int {
		$data                    = self::data();
		$id                      = $data['next']++;
		$data['entries'][ $id ]  = $entry + array(
			'id'           => (string) $id,
			'status'       => 'active',
			'is_read'      => 0,
			'is_starred'   => 0,
			'date_created' => gmdate( 'Y-m-d H:i:s' ),
		);
		$data['entries'][ $id ]['id'] = (string) $id;
		self::save( $data );
		return $id;
	}

	private static function with_objects( array $form ): array {
		$form['fields'] = array_map( static fn( $f ) => (object) $f, (array) ( $form['fields'] ?? array() ) );
		return $form;
	}

	public static function get_forms( $active = true, $trash = false, $sort_column = 'id', $sort_dir = 'ASC' ) {
		return array_values( array_map( array( self::class, 'with_objects' ), self::data()['forms'] ) );
	}

	public static function get_form( $form_id ) {
		$form = self::data()['forms'][ (int) $form_id ] ?? null;
		return is_array( $form ) ? self::with_objects( $form ) : false;
	}

	private static function matching( $form_ids, array $search_criteria ): array {
		$ids = array_map( 'intval', (array) $form_ids );
		$out = array();
		foreach ( self::data()['entries'] as $entry ) {
			if ( ! in_array( (int) $entry['form_id'], $ids, true ) ) {
				continue;
			}
			if ( isset( $search_criteria['status'] ) && $entry['status'] !== $search_criteria['status'] ) {
				continue;
			}
			foreach ( (array) ( $search_criteria['field_filters'] ?? array() ) as $filter ) {
				if ( ! is_array( $filter ) ) {
					continue;
				}
				$hay = 0 === ( $filter['key'] ?? null ) ? implode( ' ', array_map( 'strval', $entry ) ) : (string) ( $entry[ $filter['key'] ] ?? '' );
				if ( false === stripos( $hay, (string) $filter['value'] ) ) {
					continue 2;
				}
			}
			$out[] = $entry;
		}
		return $out;
	}

	public static function count_entries( $form_ids, $search_criteria = array() ) {
		return count( self::matching( $form_ids, (array) $search_criteria ) );
	}

	public static function get_entries( $form_ids, $search_criteria = array(), $sorting = null, $paging = null, &$total_count = null ) {
		$rows = self::matching( $form_ids, (array) $search_criteria );
		usort( $rows, static fn( $a, $b ) => (int) $b['id'] <=> (int) $a['id'] );
		$total_count = count( $rows );
		$paging      = (array) $paging;
		return array_slice( $rows, (int) ( $paging['offset'] ?? 0 ), (int) ( $paging['page_size'] ?? 20 ) );
	}

	public static function get_entry( $entry_id ) {
		$entry = self::data()['entries'][ (int) $entry_id ] ?? null;
		return is_array( $entry ) ? $entry : new WP_Error( 'not_found', 'Entry not found' );
	}

	public static function update_entry_property( $entry_id, $property, $value ) {
		$data = self::data();
		if ( ! isset( $data['entries'][ (int) $entry_id ] ) ) {
			return false;
		}
		$data['entries'][ (int) $entry_id ][ $property ] = $value;
		self::save( $data );
		return true;
	}

	public static function get_notes( $search_criteria = array(), $sorting = null ) {
		$out = array();
		foreach ( self::data()['notes'] as $note ) {
			if ( (int) $note['entry_id'] === (int) ( $search_criteria['entry_id'] ?? 0 ) ) {
				$out[] = (object) $note;
			}
		}
		return array_reverse( $out );
	}

	public static function add_note( $entry_id, $user_id, $user_name, $note, $note_type = 'user', $sub_type = null ) {
		$data                  = self::data();
		$id                    = $data['next']++;
		$data['notes'][ $id ]  = array(
			'id'           => $id,
			'entry_id'     => (int) $entry_id,
			'user_id'      => (int) $user_id,
			'user_name'    => (string) $user_name,
			'value'        => (string) $note,
			'note_type'    => (string) $note_type,
			'date_created' => gmdate( 'Y-m-d H:i:s' ),
		);
		self::save( $data );
		return $id;
	}

	public static function delete_note( $note_id ) {
		$data = self::data();
		if ( ! isset( $data['notes'][ (int) $note_id ] ) ) {
			return new WP_Error( 'not_found', 'Note not found' );
		}
		unset( $data['notes'][ (int) $note_id ] );
		self::save( $data );
		return 1;
	}
}

/**
 * Gravity's capability helper: any one capability, or full access.
 */
class GFCommon {

	public static function current_user_can_any( $caps ) {
		foreach ( array_merge( (array) $caps, array( 'gform_full_access' ) ) as $cap ) {
			if ( current_user_can( $cap ) ) {
				return true;
			}
		}
		return false;
	}
}

add_filter(
	'user_has_cap',
	static function ( array $allcaps ): array {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps['gform_full_access'] = true;
		}
		return $allcaps;
	}
);
