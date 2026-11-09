<?php
/**
 * Visitors' personal data in form entries (decision D17 A).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Forms;

/**
 * Entry values are masked by field kind: email, phone, name, address and file values, the IP address and user agent.
 * Other values (a message text, a chosen option) are shown. Password values are never returned, not even with full
 * data. Adapters set each field's kind from the plugin's field type, or from the field name where the plugin has no
 * such type (Contact Form 7 text fields).
 */
final class Mask {

	public const MASK = '[personal data]';

	/**
	 * Kinds masked unless full data was asked for and allowed.
	 */
	public const PERSONAL = array( 'email', 'phone', 'name', 'address', 'file' );

	/**
	 * Kind of a field from its name, for plugins without typed fields.
	 *
	 * @param string $name     Field name.
	 * @param string $fallback Kind when the name says nothing.
	 */
	public static function kind_from_name( string $name, string $fallback = 'text' ): string {
		$name = strtolower( $name );
		foreach (
			array(
				'email'   => array( 'email', 'e-mail', 'mail' ),
				'phone'   => array( 'phone', 'tel', 'mobile' ),
				'address' => array( 'address', 'street', 'postcode', 'zip', 'city' ),
				'name'    => array( 'name' ),
			) as $kind => $needles
		) {
			foreach ( $needles as $needle ) {
				if ( str_contains( $name, $needle ) ) {
					return $kind;
				}
			}
		}
		return $fallback;
	}

	/**
	 * One entry with personal values masked (or not), password values removed.
	 *
	 * @param array<string, mixed> $entry Entry from an adapter (fields with name, label, kind, value).
	 * @param bool                 $full  Whether full data was asked for and allowed.
	 * @return array<string, mixed>
	 */
	public static function entry( array $entry, bool $full ): array {
		foreach ( (array) ( $entry['fields'] ?? array() ) as $i => $field ) {
			$kind = (string) ( $field['kind'] ?? 'text' );
			if ( 'password' === $kind ) {
				$entry['fields'][ $i ]['value']     = null;
				$entry['fields'][ $i ]['has_value'] = '' !== self::flat( $field['value'] ?? '' );
			} elseif ( ! $full && in_array( $kind, self::PERSONAL, true ) && '' !== self::flat( $field['value'] ?? '' ) ) {
				$entry['fields'][ $i ]['value'] = self::MASK;
			}
		}
		if ( ! $full ) {
			foreach ( array( 'ip', 'user_agent' ) as $key ) {
				if ( ! empty( $entry['meta'][ $key ] ) ) {
					$entry['meta'][ $key ] = self::MASK;
				}
			}
			foreach ( array( 'name', 'email' ) as $key ) {
				if ( ! empty( $entry['sender'][ $key ] ) ) {
					$entry['sender'][ $key ] = self::MASK;
				}
			}
			foreach ( (array) ( $entry['notes'] ?? array() ) as $i => $note ) {
				if ( is_email( (string) ( $note['author'] ?? '' ) ) ) {
					$entry['notes'][ $i ]['author'] = self::MASK;
				}
			}
		}
		$entry['personal_data'] = $full ? 'shown' : 'masked';
		return $entry;
	}

	/**
	 * A value as one string (arrays joined), to test for emptiness.
	 *
	 * @param mixed $value Value.
	 */
	private static function flat( mixed $value ): string {
		if ( is_array( $value ) ) {
			return implode( '', array_map( array( self::class, 'flat' ), $value ) );
		}
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
