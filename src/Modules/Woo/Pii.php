<?php
/**
 * Customer personal data rules (D12).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Woo;

/**
 * Masks customer data by default. Full addresses, email, phone, IP and user agent only reach a user who asked for
 * them (include_pii) and may edit the object; tools call these helpers before returning anything.
 */
final class Pii {

	public const MASK = '[personal data]';

	/**
	 * "jane@example.com" → "j***@example.com".
	 *
	 * @param string $email Email.
	 */
	public static function email( string $email ): string {
		$at = strrpos( $email, '@' );
		if ( false === $at || 0 === $at ) {
			return '' === $email ? '' : '***';
		}
		return substr( $email, 0, 1 ) . '***' . substr( $email, $at );
	}

	/**
	 * Personal data inside third-party data (WooCommerce report rows): emails masked, street-level address, phone,
	 * user name and IP removed, recursively.
	 *
	 * @param array<mixed> $data Data.
	 * @param int          $depth Recursion depth.
	 * @return array<mixed>
	 */
	public static function scrub( array $data, int $depth = 0 ): array {
		$drop = array( 'address', 'address_1', 'address_2', 'city', 'state', 'postcode', 'phone', 'billing_phone', 'username', 'ip_address', 'customer_ip_address', 'user_agent', 'customer_user_agent' );
		$out  = array();
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && in_array( strtolower( $key ), $drop, true ) ) {
				continue;
			}
			if ( is_array( $value ) || is_object( $value ) ) {
				$out[ $key ] = $depth > 16 ? array() : self::scrub( (array) $value, $depth + 1 );
			} elseif ( is_string( $key ) && is_string( $value ) && str_contains( strtolower( $key ), 'email' ) ) {
				$out[ $key ] = self::email( $value );
			} else {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * An address in the tool shape: everything when allowed, otherwise names and country only plus a masked email.
	 *
	 * @param array<string, mixed> $address WooCommerce address (get_address()).
	 * @param bool                 $full    Show everything.
	 * @return array<string, string>
	 */
	public static function address( array $address, bool $full ): array {
		$address = array_map( static fn( $value ) => is_scalar( $value ) ? (string) $value : '', $address );
		if ( $full ) {
			return $address;
		}
		$out = array_intersect_key( $address, array_flip( array( 'first_name', 'last_name', 'company', 'country' ) ) );
		if ( isset( $address['email'] ) ) {
			$out['email'] = self::email( $address['email'] );
		}
		return $out;
	}
}
