<?php
/**
 * Path rules for developer tools (decision D21 A).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Dev;

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Files are addressed relative to a root ("WordPress" = ABSPATH, "content" = WP_CONTENT_DIR) and resolved with
 * realpath(), so "../" and symbolic links cannot leave the root. Secret files are refused by name and folder
 * wherever they are. Writes are allowed only inside plugin folders, theme folders and the KuDiagnostics sandbox, never in
 * KuDiagnostics's own folder or in must-use plugins.
 */
final class DevPaths {

	/**
	 * File names never read or written (case-insensitive patterns on the base name).
	 */
	private const SECRET_NAMES = array(
		'/^wp-config(?!-sample).*\.php$/i',
		'/^\.env(\..*)?$/i',
		'/\.(pem|key|p12|pfx|jks|keystore|kdbx|ppk)$/i',
		'/^id_(rsa|dsa|ecdsa|ed25519)(\.pub)?$/i',
		'/^\.htpasswd$/i',
		'/^auth\.json$/i',
		'/^\.npmrc$|^\.pypirc$|^\.netrc$/i',
		'/\.(sql|sql\.gz|sqlite|db)$/i',
		'/^\.tunnel-admin\.txt$/i',
	);

	/**
	 * Folder names never entered.
	 */
	private const SECRET_DIRS = array( '.git', '.ssh', '.svn', '.aws', '.config' );

	/**
	 * Extensions developer tools may write.
	 */
	private const WRITABLE = array( 'php', 'js', 'mjs', 'css', 'scss', 'json', 'txt', 'md', 'html', 'svg', 'xml', 'yml', 'yaml', 'po', 'pot', 'twig' );

	/**
	 * Root folders.
	 *
	 * @return array<string, string>
	 */
	public static function roots(): array {
		return array(
			'wordpress' => untrailingslashit( (string) realpath( ABSPATH ) ),
			'content'   => untrailingslashit( (string) realpath( WP_CONTENT_DIR ) ),
		);
	}

	/**
	 * Root input schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function root_input(): array {
		return array(
			'type'        => 'string',
			'enum'        => array_keys( self::roots() ),
			'default'     => 'content',
			'description' => 'content = wp-content (plugins, themes, uploads); wordpress = the WordPress folder.',
		);
	}

	/**
	 * Normalise a path for comparisons.
	 *
	 * @param string $path Path.
	 */
	private static function norm( string $path ): string {
		return str_replace( '\\', '/', $path );
	}

	/**
	 * Whether a resolved path is a secret by name or folder.
	 *
	 * @param string $path Absolute path.
	 */
	public static function is_secret( string $path ): bool {
		$path = self::norm( $path );
		foreach ( explode( '/', $path ) as $part ) {
			if ( in_array( strtolower( $part ), self::SECRET_DIRS, true ) ) {
				return true;
			}
		}
		$base = basename( $path );
		foreach ( self::SECRET_NAMES as $pattern ) {
			if ( preg_match( $pattern, $base ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a path is inside a folder (both absolute).
	 *
	 * @param string $path   Path.
	 * @param string $folder Folder.
	 */
	public static function inside( string $path, string $folder ): bool {
		$path   = self::norm( $path );
		$folder = rtrim( self::norm( $folder ), '/' );
		return '' !== $folder && ( $path === $folder || str_starts_with( $path, $folder . '/' ) );
	}

	/**
	 * Resolve an existing file or folder inside a root, or a ToolError.
	 *
	 * @param string $root Root id.
	 * @param string $rel  Relative path ("" = the root).
	 * @throws ToolError When the path leaves the root, does not exist or is secret.
	 */
	public static function existing( string $root, string $rel ): string {
		$base = self::roots()[ $root ] ?? '';
		if ( '' === $base ) {
			throw new ToolError( 'Unknown root "' . esc_html( $root ) . '".' );
		}
		$path = realpath( $base . '/' . ltrim( $rel, '/\\' ) );
		if ( false === $path || ! self::inside( $path, $base ) ) {
			throw new ToolError( 'No such file or folder inside ' . esc_html( $root ) . ': ' . esc_html( $rel ) );
		}
		if ( self::is_secret( $path ) ) {
			throw new ToolError( 'This file holds secrets (configuration, keys or credentials) and is never read or changed by KuDiagnostics.' );
		}
		return $path;
	}

	/**
	 * Path relative to its root, for results.
	 *
	 * @param string $path Absolute path.
	 */
	public static function relative( string $path ): string {
		$path = self::norm( $path );
		foreach ( array_reverse( self::roots() ) as $base ) {
			if ( self::inside( $path, $base ) ) {
				return ltrim( substr( $path, strlen( self::norm( $base ) ) ), '/' );
			}
		}
		return basename( $path );
	}

	/**
	 * Folder of the KuDiagnostics sandbox.
	 */
	public static function sandbox(): string {
		return self::roots()['content'] . '/kuduy-sandbox';
	}

	/**
	 * Resolve a file to write (it may not exist yet), or a ToolError.
	 *
	 * @param string $root Root id.
	 * @param string $rel  Relative path.
	 * @throws ToolError When the target is not in an approved folder or has a disallowed type.
	 */
	public static function writable( string $root, string $rel ): string {
		$base = self::roots()[ $root ] ?? '';
		$rel  = self::norm( ltrim( $rel, '/\\' ) );
		if ( '' === $base || '' === $rel || str_contains( '/' . $rel . '/', '/../' ) || str_contains( $rel, "\0" ) ) {
			throw new ToolError( 'Give a relative file path without "..".' );
		}
		$path   = self::norm( $base . '/' . $rel );
		$parent = dirname( $path );
		while ( ! is_dir( $parent ) && dirname( $parent ) !== $parent ) {
			$parent = dirname( $parent );
		}
		$real_parent = realpath( $parent );
		if ( false === $real_parent || ! self::inside( $real_parent, $base ) || ( file_exists( $path ) && ! self::inside( (string) realpath( $path ), $base ) ) ) {
			throw new ToolError( 'The path leaves the ' . esc_html( $root ) . ' folder.' );
		}
		if ( self::is_secret( $path ) ) {
			throw new ToolError( 'This file holds secrets (configuration, keys or credentials) and is never read or changed by KuDiagnostics.' );
		}
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::WRITABLE, true ) ) {
			throw new ToolError( 'Files of type ".' . esc_html( $ext ) . '" cannot be written; allowed: ' . esc_html( implode( ', ', self::WRITABLE ) ) . '.' );
		}
		$own = untrailingslashit( (string) realpath( dirname( __DIR__, 3 ) ) );
		if ( self::inside( $path, $own ) || ( '' !== (string) realpath( dirname( $path ) ) && self::inside( (string) realpath( dirname( $path ) ), $own ) ) ) {
			throw new ToolError( 'KuDiagnostics does not change its own files.' );
		}
		$approved = array( self::sandbox() );
		foreach ( array( WP_PLUGIN_DIR, get_theme_root() ) as $folder ) {
			$real = realpath( $folder );
			if ( false !== $real ) {
				$approved[] = $real;
			}
		}
		foreach ( $approved as $folder ) {
			if ( self::inside( $path, $folder ) && self::norm( $path ) !== self::norm( $folder ) ) {
				if ( self::sandbox() !== $folder && self::norm( dirname( $path ) ) === rtrim( self::norm( $folder ), '/' ) ) {
					throw new ToolError( 'Put the file inside a plugin or theme folder (e.g. my-plugin/my-plugin.php), not loose in the plugins or themes folder.' );
				}
				return $path;
			}
		}
		throw new ToolError( 'Files can be written only in a plugin folder, a theme folder or wp-content/kuduy-sandbox (must-use plugins and WordPress itself are not changed).' );
	}

	/**
	 * Plugin or theme the file belongs to, if it is active: "plugin:dir" / "theme:dir", or null.
	 *
	 * @param string $path Absolute path.
	 */
	public static function active_owner( string $path ): ?string {
		$plugins = (string) realpath( WP_PLUGIN_DIR );
		if ( '' !== $plugins && self::inside( $path, $plugins ) ) {
			$dir = explode( '/', ltrim( substr( self::norm( $path ), strlen( self::norm( $plugins ) ) ), '/' ) )[0];
			foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
				if ( str_starts_with( (string) $file, $dir . '/' ) ) {
					return 'plugin:' . $dir;
				}
			}
			return null;
		}
		$themes = (string) realpath( get_theme_root() );
		if ( '' !== $themes && self::inside( $path, $themes ) ) {
			$dir = explode( '/', ltrim( substr( self::norm( $path ), strlen( self::norm( $themes ) ) ), '/' ) )[0];
			return in_array( $dir, array( get_stylesheet(), get_template() ), true ) ? 'theme:' . $dir : null;
		}
		return null;
	}
}
