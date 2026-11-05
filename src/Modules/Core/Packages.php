<?php
/**
 * Shared rules for plugin and theme tools: wordpress.org slugs only, direct filesystem only, silent upgrader skin,
 * and the confirm policy (disruptive actions first return an impact report and change nothing).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Helpers only; no tool of its own.
 */
final class Packages {

	/**
	 * JSON Schema for the confirm flag.
	 *
	 * @return array<string, mixed>
	 */
	public static function confirm_input(): array {
		return array(
			'type'        => 'boolean',
			'default'     => false,
			'description' => 'Without true, nothing changes: the result describes what would happen (impact). Ask the user, then call again with confirm: true.',
		);
	}

	/**
	 * Load the admin code the upgraders need.
	 */
	public static function load_admin(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/theme-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}

	/**
	 * A wordpress.org slug (installs come from wordpress.org only, never from arbitrary URLs).
	 *
	 * @param string $slug Slug.
	 * @throws ToolError When the slug is not valid.
	 */
	public static function slug( string $slug ): string {
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{0,199}$/', $slug ) ) {
			throw new ToolError( 'Use the wordpress.org slug, e.g. "classic-editor" (lower-case letters, digits and dashes).' );
		}
		return $slug;
	}

	/**
	 * Refuse when WordPress would need FTP credentials (it would print a form instead of installing).
	 *
	 * @throws ToolError When files cannot be written directly.
	 */
	public static function require_direct_filesystem(): void {
		if ( 'direct' !== get_filesystem_method() ) {
			throw new ToolError( 'This site needs FTP/SSH credentials to change plugin or theme files, which cannot be given through MCP. Install or update from wp-admin instead.' );
		}
	}

	/**
	 * Run an upgrader action with a silent skin; turn every failure into a ToolError.
	 *
	 * @param callable(\Automatic_Upgrader_Skin): mixed $action Receives the skin, returns the upgrader result.
	 * @throws ToolError When the upgrader fails.
	 */
	public static function run( callable $action ): void {
		self::require_direct_filesystem();
		$skin = new \Automatic_Upgrader_Skin();
		// Like WordPress's background updates: an active plugin is not deactivated while its files are replaced.
		add_filter( 'wp_doing_cron', '__return_true' );
		try {
			$result = $action( $skin );
		} finally {
			remove_filter( 'wp_doing_cron', '__return_true' );
		}
		if ( is_wp_error( $result ) ) {
			throw new ToolError( esc_html( $result->get_error_message() ) );
		}
		$error = $skin->result;
		if ( is_wp_error( $error ) ) {
			throw new ToolError( esc_html( $error->get_error_message() ) );
		}
		if ( true !== $result && null !== $result ) {
			$messages = array_map( 'wp_strip_all_tags', (array) $skin->get_upgrade_messages() );
			throw new ToolError( 'WordPress did not finish: ' . esc_html( implode( ' ', array_slice( $messages, -3 ) ) ) );
		}
	}
}
