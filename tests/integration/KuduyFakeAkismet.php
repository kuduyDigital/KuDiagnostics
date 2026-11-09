<?php
/**
 * Stand-in for the Akismet plugin in tests: Flamingo only checks Akismet::get_api_key() and calls
 * Akismet::http_post(). Nothing leaves the test site; calls are counted. Off until a test sets a key.
 *
 * @package Kuduy\Mcp
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile, Squiz.Classes.ClassFileName, PEAR.NamingConventions.ValidClassName, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- test double with the real plugin's class name.
if ( ! class_exists( 'Akismet' ) ) {
	/**
	 * Fake Akismet.
	 */
	final class Akismet {

		/**
		 * API key ('' = Akismet not configured).
		 *
		 * @var string
		 */
		public static string $key = '';

		/**
		 * Recorded submissions (submit-spam / submit-ham).
		 *
		 * @var list<string>
		 */
		public static array $calls = array();

		/**
		 * API key.
		 */
		public static function get_api_key(): string {
			return self::$key;
		}

		/**
		 * Record a call instead of sending it.
		 *
		 * @param string $request Query string.
		 * @param string $path    Endpoint.
		 * @return array{0: array<string, mixed>, 1: string}
		 */
		public static function http_post( string $request, string $path ): array {
			self::$calls[] = $path;
			return array( array(), 'true' );
		}
	}
}
