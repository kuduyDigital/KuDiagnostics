<?php
/**
 * Tool: kuduy-check-update.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Plugin;
use Kuduy\Mcp\Update\GitHubUpdater;

/**
 * Reports whether a newer KUDUY MCP release exists. Installing stays in WordPress (Plugins screen or auto-updates).
 */
final class CheckUpdate implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-check-update';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Check for a KUDUY MCP update', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Compares the installed KUDUY MCP version with the latest GitHub release and returns the release notes. Does not install anything; updates are installed from the WordPress Plugins screen or by WordPress auto-updates.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'refresh' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Ask GitHub now instead of using the result cached for up to 12 hours.',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'  => true,
			'openWorldHint' => true,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:read';
	}

	/**
	 * Only users who may update plugins.
	 *
	 * @param array<string, mixed> $input Unused.
	 */
	public function capability( array $input ): string {
		return 'update_plugins';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return true;
	}

	/**
	 * Check.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ): array {
		$release = GitHubUpdater::latest( ! empty( $input['refresh'] ) );
		return array(
			'installed_version' => Plugin::VERSION,
			'latest_version'    => $release['version'] ?? null,
			'update_available'  => null !== $release && version_compare( $release['version'], Plugin::VERSION, '>' ),
			'release_url'       => $release['url'] ?? null,
			'release_notes'     => null === $release ? null : mb_substr( wp_strip_all_tags( $release['notes'] ), 0, 2000 ),
			'install_from'      => admin_url( 'plugins.php' ),
		);
	}
}
