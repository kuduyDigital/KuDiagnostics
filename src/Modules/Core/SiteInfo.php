<?php
/**
 * Tool: kuduy-site-info.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Plugin;

/**
 * Basic facts about the site and the connected user.
 */
final class SiteInfo implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-site-info';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Site info', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Returns the site name, URL, language, timezone, WordPress and KUDUY MCP versions, active theme, and the connected WordPress user with their roles. Call this first to learn what site you are working on.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:read';
	}

	/**
	 * Any logged-in user may read basic site info.
	 *
	 * @param array<string, mixed> $input Unused.
	 */
	public function capability( array $input ): string {
		return 'read';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return true;
	}

	/**
	 * Collect the info; PHP version only for site managers.
	 *
	 * @param array<string, mixed> $input Unused.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ): array {
		$user = wp_get_current_user();
		$info = array(
			'name'              => get_bloginfo( 'name' ),
			'description'       => get_bloginfo( 'description' ),
			'url'               => home_url( '/' ),
			'admin_url'         => admin_url(),
			'language'          => get_bloginfo( 'language' ),
			'timezone'          => wp_timezone_string(),
			'wordpress_version' => get_bloginfo( 'version' ),
			'kuduy_mcp_version' => Plugin::VERSION,
			'theme'             => (string) wp_get_theme()->get( 'Name' ),
			'is_multisite'      => is_multisite(),
			'user'              => array(
				'id'           => $user->ID,
				'display_name' => $user->display_name,
				'roles'        => array_values( $user->roles ),
			),
		);
		if ( current_user_can( 'manage_options' ) ) {
			$info['php_version'] = PHP_VERSION;
		}
		return $info;
	}
}
