<?php
/**
 * Strips secrets from tool results and audit rows (architecture §8).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Audit;

/**
 * Masks values under secret-looking keys, and values that look like credentials wherever they appear.
 */
final class Redactor {

	public const MASK = '[redacted]';

	private const SECRET_KEY = '/(pass(word|wd)?$|^pwd$|user_pass|secret|token|api[_-]?key|private[_-]?key|auth[_-]?key|^salt$|_salt$|nonce_key|logged_in_key|cookie|authorization|credential|license[_-]?key|signature|consumer[_-]?key|order[_-]?key|cart[_-]?hash|payment[_-]?token)/i';

	/**
	 * Bearer credentials, our own access/refresh tokens, WordPress/bcrypt/argon2 password hashes.
	 */
	private const SECRET_VALUE = array(
		'/^Bearer\s+\S+/i',
		'/kmcp_(at|rt)_[A-Za-z0-9_-]{8,}/',
		'/^\$(P|H|wp)\$|^\$2[aby]\$|^\$argon2/',
	);

	/**
	 * Redact an array (tool results, arguments).
	 *
	 * @param array<mixed> $data Data.
	 * @return array<mixed>
	 */
	public static function redact_array( array $data ): array {
		return (array) self::redact( $data );
	}

	/**
	 * Redact recursively.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Recursion depth (deeper data is dropped).
	 * @return mixed
	 */
	public static function redact( mixed $value, int $depth = 0 ): mixed {
		if ( $depth > 32 ) {
			return self::MASK;
		}
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( is_array( $value ) ) {
			// A settings field of type password (WooCommerce gateways and integrations: { id, type, value }), whatever its name.
			if ( 'password' === ( $value['type'] ?? null ) && array_key_exists( 'value', $value ) && is_scalar( $value['value'] ) && '' !== $value['value'] ) {
				$value['value'] = self::MASK;
			}
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = is_string( $key ) && preg_match( self::SECRET_KEY, $key ) && ! is_array( $item ) && ! is_object( $item )
					? self::MASK
					: self::redact( $item, $depth + 1 );
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			foreach ( self::SECRET_VALUE as $pattern ) {
				if ( preg_match( $pattern, $value ) ) {
					return self::MASK;
				}
			}
		}
		return $value;
	}
}
