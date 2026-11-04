<?php
/**
 * OAuth scopes and their hierarchy (architecture §8).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\OAuth;

/**
 * Read ⊂ write ⊂ admin. `kuduy:code` stands alone and exists only when D4's constant is set.
 */
final class Scopes {

	public const READ = 'kuduy:read';

	public const WRITE = 'kuduy:write';

	public const ADMIN = 'kuduy:admin';

	public const CODE = 'kuduy:code';

	/**
	 * Scopes requested when a client asks for none, and named in 401 challenges.
	 */
	public const DEFAULT = array( self::READ, self::WRITE );

	private const RANK = array(
		self::READ  => 1,
		self::WRITE => 2,
		self::ADMIN => 3,
	);

	/**
	 * Resource scopes this site offers.
	 *
	 * @return list<string>
	 */
	public static function supported(): array {
		$scopes = array( self::READ, self::WRITE, self::ADMIN );
		if ( defined( 'KUDUY_MCP_ALLOW_CODE_EXEC' ) && true === constant( 'KUDUY_MCP_ALLOW_CODE_EXEC' ) ) {
			$scopes[] = self::CODE;
		}
		return $scopes;
	}

	/**
	 * Known scopes from a space-separated string, in canonical order (unknown ones and offline_access dropped).
	 *
	 * @param string $scope Scope string.
	 * @return list<string>
	 */
	public static function parse( string $scope ): array {
		$asked = preg_split( '/\s+/', trim( $scope ) );
		return array_values( array_intersect( self::supported(), false === $asked ? array() : $asked ) );
	}

	/**
	 * Whether granted scopes cover the required one.
	 *
	 * @param list<string> $granted  Granted scopes.
	 * @param string       $required Required scope.
	 */
	public static function allows( array $granted, string $required ): bool {
		if ( in_array( $required, $granted, true ) ) {
			return true;
		}
		if ( ! isset( self::RANK[ $required ] ) ) {
			return false;
		}
		foreach ( $granted as $scope ) {
			if ( ( self::RANK[ $scope ] ?? 0 ) >= self::RANK[ $required ] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Requested scopes this user's capabilities justify.
	 *
	 * @param list<string> $requested Requested scopes.
	 * @param \WP_User     $user      User.
	 * @return list<string>
	 */
	public static function for_user( array $requested, \WP_User $user ): array {
		$allowed = array_filter(
			$requested,
			static fn( string $scope ): bool => match ( $scope ) {
				self::READ  => user_can( $user, 'read' ),
				self::WRITE => user_can( $user, 'edit_posts' ),
				self::ADMIN => user_can( $user, 'manage_options' ),
				self::CODE  => user_can( $user, 'manage_options' ) && ( user_can( $user, 'unfiltered_html' ) || user_can( $user, 'edit_plugins' ) ),
				default     => false,
			}
		);
		return array_values( $allowed );
	}
}
