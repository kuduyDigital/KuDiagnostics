<?php
/**
 * Masking credentials in file text and log lines (decision D21 A).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Dev;

use Kuduy\Mcp\Audit\Redactor;

/**
 * Source files and logs can hold hard-coded credentials. Values of constants and array keys that look like secrets
 * (`define( 'STRIPE_SECRET_KEY', '…' )`, `'api_key' => '…'`, `password = …`), bearer tokens and password hashes are
 * replaced by [redacted] before text leaves the site.
 */
final class DevText {

	/**
	 * Mask credential-looking values in a text.
	 *
	 * @param string $text Text.
	 */
	public static function mask( string $text ): string {
		$name = '[A-Za-z0-9_\-]*(?:pass(?:word|wd)?|secret|token|api[_-]?key|private[_-]?key|auth[_-]?key|salt|credential|license[_-]?key|signature)[A-Za-z0-9_\-]*';
		$text = (string) preg_replace( '/(define\(\s*[\'"]' . $name . '[\'"]\s*,\s*)([\'"])(?:\\\\.|(?!\2).)*\2/i', '$1\'' . Redactor::MASK . '\'', $text );
		$text = (string) preg_replace( '/([\'"]' . $name . '[\'"]\s*(?:=>|:)\s*)([\'"])(?:\\\\.|(?!\2).)*\2/i', '$1"' . Redactor::MASK . '"', $text );
		// A literal string assigned to a secret-looking variable or key: $db_password = '…', password: "…".
		$text = (string) preg_replace( '/(\b' . $name . '\s*[=:]\s*)([\'"])(?:\\\\.|(?!\2).)*\2/i', '$1\'' . Redactor::MASK . '\'', $text );
		// key=value pairs in logs, .ini-style lines and URLs; PHP variables and calls on the right are left alone.
		$text = (string) preg_replace( '/(\b' . $name . '\s*=\s*)(?![\s$(\'"])[^\s;&()]+(?=[\s;&]|$)/im', '$1' . Redactor::MASK, $text );
		$text = (string) preg_replace( '/Bearer\s+[A-Za-z0-9._~+\/=-]{8,}/', 'Bearer ' . Redactor::MASK, $text );
		$text = (string) preg_replace( '/kmcp_(at|rt)_[A-Za-z0-9_-]{8,}/', Redactor::MASK, $text );
		return (string) preg_replace( '/\$(?:P|H|wp)\$[.\/A-Za-z0-9$]{20,}|\$2[aby]\$\d\d\$[.\/A-Za-z0-9]{53}|\$argon2[a-z]*\$[^\s\'"]+/', Redactor::MASK, $text );
	}

	/**
	 * Whether a string looks binary.
	 *
	 * @param string $data Data.
	 */
	public static function binary( string $data ): bool {
		return str_contains( substr( $data, 0, 8192 ), "\0" );
	}
}
