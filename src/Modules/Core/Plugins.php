<?php
/**
 * Tools: kuduy-plugin-list, kuduy-plugin-install, kuduy-plugin-activate, kuduy-plugin-deactivate,
 * kuduy-plugin-update (one class, five modes).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Update\GitHubUpdater;

/**
 * Installs come from wordpress.org by slug only. Deactivating and updating are confirm-first: without
 * confirm: true they report the impact and change nothing. KuDiagnostics never deactivates or updates itself through MCP.
 * Deleting plugins is not offered.
 */
final class Plugins implements Tool, Snapshottable {

	/**
	 * Plugins that usually carry a site; deactivating them is called out in the impact report.
	 */
	private const ESSENTIAL = array(
		'elementor/elementor.php'                     => 'page builder',
		'elementor-pro/elementor-pro.php'             => 'page builder',
		'woocommerce/woocommerce.php'                 => 'shop',
		'wordpress-seo/wp-seo.php'                    => 'SEO',
		'seo-by-rank-math/rank-math.php'              => 'SEO',
		'all-in-one-seo-pack/all_in_one_seo_pack.php' => 'SEO',
		'wp-seopress/seopress.php'                    => 'SEO',
		'advanced-custom-fields/acf.php'              => 'custom fields',
		'advanced-custom-fields-pro/acf.php'          => 'custom fields',
	);

	/**
	 * Mode.
	 *
	 * @param string $mode list | install | activate | deactivate | update.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-plugin-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'list'       => __( 'List plugins', 'kuduy-mcp' ),
			'install'    => __( 'Install a plugin', 'kuduy-mcp' ),
			'activate'   => __( 'Activate a plugin', 'kuduy-mcp' ),
			'deactivate' => __( 'Deactivate a plugin', 'kuduy-mcp' ),
			default      => __( 'Update a plugin', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'list'       => 'Lists installed plugins: file, name, version, active, available update, required WordPress/PHP, and plugins they depend on.',
			'install'    => 'Installs a plugin from wordpress.org by slug (never from other URLs), optionally activating it.',
			'activate'   => 'Activates an installed plugin (by file, see kuduy-plugin-list). Undo with kuduy-snapshot-restore.',
			'deactivate' => 'Deactivates a plugin. Confirm-first: without confirm: true it only reports the impact (plugins that depend on it, whether it runs the page builder, shop or SEO). KuDiagnostics itself cannot be deactivated here. Undo re-activates.',
			default      => 'Updates a plugin to the version WordPress offers. Confirm-first: without confirm: true it only reports current and new version. Not undoable (files are replaced). Update KuDiagnostics itself from Dashboard > Updates.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$file   = array(
			'type'        => 'string',
			'description' => 'Plugin file relative to the plugins folder, e.g. "akismet/akismet.php".',
		);
		$schema = match ( $this->mode ) {
			'list'       => array( 'properties' => array() ),
			'install'    => array(
				'properties' => array(
					'slug'     => array( 'type' => 'string' ),
					'activate' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'required'   => array( 'slug' ),
			),
			'activate'   => array(
				'properties' => array( 'file' => $file ),
				'required'   => array( 'file' ),
			),
			default      => array(
				'properties' => array(
					'file'    => $file,
					'confirm' => Packages::confirm_input(),
				),
				'required'   => array( 'file' ),
			),
		};//end match
		return array( 'type' => 'object' ) + $schema + array( 'additionalProperties' => false );
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		$plugin = array(
			'type'       => 'object',
			'properties' => array(
				'file'             => array( 'type' => 'string' ),
				'name'             => array( 'type' => 'string' ),
				'version'          => array( 'type' => 'string' ),
				'active'           => array( 'type' => 'boolean' ),
				'update'           => array( 'type' => array( 'string', 'null' ) ),
				'requires_wp'      => array( 'type' => 'string' ),
				'requires_php'     => array( 'type' => 'string' ),
				'requires_plugins' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'required'   => array( 'file', 'name', 'version', 'active' ),
		);
		if ( 'list' === $this->mode ) {
			return array(
				'type'       => 'object',
				'properties' => array(
					'plugins' => array(
						'type'  => 'array',
						'items' => $plugin,
					),
				),
				'required'   => array( 'plugins' ),
			);
		}
		$plugin['properties'] += array(
			'done'         => array(
				'type'        => 'boolean',
				'description' => 'False when only the impact was reported (confirm needed).',
			),
			'impact'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'_snapshot_id' => array( 'type' => 'integer' ),
		);
		$plugin['required'][]  = 'done';
		return $plugin;
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return 'list' === $this->mode ? Content::read_only() : array(
			'readOnlyHint'    => false,
			'destructiveHint' => in_array( $this->mode, array( 'activate', 'deactivate', 'update' ), true ),
			'idempotentHint'  => 'install' !== $this->mode,
			'openWorldHint'   => in_array( $this->mode, array( 'install', 'update' ), true ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'list' === $this->mode ? 'kuduy:read' : 'kuduy:admin';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		$file = (string) ( $input['file'] ?? '' );
		return match ( $this->mode ) {
			'install'    => 'install_plugins',
			'update'     => 'update_plugins',
			'activate'   => '' === $file ? 'activate_plugins' : array( 'activate_plugin', $file ),
			'deactivate' => '' === $file ? 'activate_plugins' : array( 'deactivate_plugin', $file ),
			default      => 'activate_plugins',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( ! in_array( $this->mode, array( 'activate', 'deactivate' ), true ) || ( 'deactivate' === $this->mode && empty( $input['confirm'] ) ) ) {
			return null;
		}
		Packages::load_admin();
		$file = (string) $input['file'];
		return array(
			'object_type' => 'plugin',
			'object_id'   => $file,
			'payload'     => array(
				'file'   => $file,
				'active' => is_plugin_active( $file ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the plugin cannot be put back.
	 */
	public function restore( array $payload ): void {
		Packages::load_admin();
		$file = (string) ( $payload['file'] ?? '' );
		if ( ! empty( $payload['active'] ) ) {
			$result = activate_plugin( $file );
			if ( is_wp_error( $result ) ) {
				throw new ToolError( 'Undo failed: ' . esc_html( $result->get_error_message() ) );
			}
		} else {
			deactivate_plugins( $file );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the plugin or request is invalid or WordPress refuses.
	 */
	public function execute( array $input ): array {
		Packages::load_admin();
		if ( 'list' === $this->mode ) {
			$out = array();
			foreach ( array_keys( get_plugins() ) as $file ) {
				$out[] = self::shape( (string) $file );
			}
			return array( 'plugins' => $out );
		}
		if ( 'install' === $this->mode ) {
			return $this->install( Packages::slug( (string) $input['slug'] ), ! empty( $input['activate'] ) );
		}

		$file = (string) $input['file'];
		if ( ! isset( get_plugins()[ $file ] ) ) {
			throw new ToolError( 'Plugin not installed: use the file from kuduy-plugin-list, e.g. "akismet/akismet.php".' );
		}
		AuditLog::target( 'plugin', $file );
		$self = GitHubUpdater::basename() === $file;

		if ( 'activate' === $this->mode ) {
			$result = activate_plugin( $file );
			if ( is_wp_error( $result ) ) {
				throw new ToolError( 'WordPress could not activate it: ' . esc_html( $result->get_error_message() ) );
			}
			return self::shape( $file ) + array( 'done' => true );
		}

		if ( 'deactivate' === $this->mode ) {
			if ( $self ) {
				throw new ToolError( 'KuDiagnostics cannot deactivate itself through MCP (this connection would stop). Use the Plugins screen.' );
			}
			if ( ! is_plugin_active( $file ) ) {
				return self::shape( $file ) + array(
					'done'   => true,
					'impact' => array( 'Already inactive; nothing changed.' ),
				);
			}
			$impact = self::deactivation_impact( $file );
			if ( empty( $input['confirm'] ) ) {
				return self::shape( $file ) + array(
					'done'   => false,
					'impact' => $impact,
				);
			}
			deactivate_plugins( $file );
			return self::shape( $file ) + array(
				'done'   => true,
				'impact' => $impact,
			);
		}//end if

		// Update.
		if ( $self ) {
			throw new ToolError( 'Update KuDiagnostics from Dashboard > Updates (or WordPress auto-updates), not through its own connection.' );
		}
		$offer = get_site_transient( 'update_plugins' );
		$new   = is_object( $offer ) && isset( $offer->response[ $file ] ) ? (string) $offer->response[ $file ]->new_version : '';
		if ( '' === $new ) {
			throw new ToolError( 'WordPress has no update for this plugin right now (see kuduy-plugin-list).' );
		}
		$impact = array( 'Updates ' . get_plugins()[ $file ]['Name'] . ' from ' . get_plugins()[ $file ]['Version'] . ' to ' . $new . '. Files are replaced; this cannot be undone here.' );
		if ( empty( $input['confirm'] ) ) {
			return self::shape( $file ) + array(
				'done'   => false,
				'impact' => $impact,
			);
		}
		Packages::run( static fn( \Automatic_Upgrader_Skin $skin ) => ( new \Plugin_Upgrader( $skin ) )->upgrade( $file ) );
		wp_clean_plugins_cache();
		return self::shape( $file ) + array(
			'done'   => true,
			'impact' => $impact,
		);
	}

	/**
	 * Install from wordpress.org.
	 *
	 * @param string $slug     Slug.
	 * @param bool   $activate Activate afterwards.
	 * @return array<string, mixed>
	 * @throws ToolError When the plugin is unknown, installed already, or the install fails.
	 */
	private function install( string $slug, bool $activate ): array {
		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( str_starts_with( (string) $file, $slug . '/' ) ) {
				throw new ToolError( 'Already installed as ' . esc_html( (string) $file ) . '.' );
			}
		}
		$info = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array( 'sections' => false ),
			)
		);
		if ( is_wp_error( $info ) || ! is_object( $info ) || empty( $info->download_link ) ) {
			throw new ToolError( 'wordpress.org has no plugin with this slug.' );
		}
		$link = (string) $info->download_link;
		Packages::run( static fn( \Automatic_Upgrader_Skin $skin ) => ( new \Plugin_Upgrader( $skin ) )->install( $link ) );
		wp_clean_plugins_cache();
		$file = '';
		foreach ( array_keys( get_plugins() ) as $candidate ) {
			if ( str_starts_with( (string) $candidate, $slug . '/' ) ) {
				$file = (string) $candidate;
			}
		}
		if ( '' === $file ) {
			throw new ToolError( 'The plugin was downloaded but WordPress cannot find its main file.' );
		}
		AuditLog::target( 'plugin', $file );
		if ( $activate ) {
			$result = activate_plugin( $file );
			if ( is_wp_error( $result ) ) {
				throw new ToolError( 'Installed, but WordPress could not activate it: ' . esc_html( $result->get_error_message() ) );
			}
		}
		return self::shape( $file ) + array( 'done' => true );
	}

	/**
	 * What deactivating would affect.
	 *
	 * @param string $file Plugin file.
	 * @return list<string>
	 */
	private static function deactivation_impact( string $file ): array {
		$impact = array();
		if ( isset( self::ESSENTIAL[ $file ] ) ) {
			$impact[] = 'This plugin runs the site\'s ' . self::ESSENTIAL[ $file ] . '; pages or features that use it will break or disappear.';
		}
		// Core loads dependency data only on wp-admin screens; MCP requests must load it themselves.
		\WP_Plugin_Dependencies::initialize();
		if ( \WP_Plugin_Dependencies::has_active_dependents( $file ) ) {
			$impact[] = 'Active plugins that require it: ' . implode( ', ', \WP_Plugin_Dependencies::get_dependent_names( $file ) ) . '. They may stop working.';
		}
		if ( ! $impact ) {
			$impact[] = 'No other active plugin declares that it needs this plugin.';
		}
		return $impact;
	}

	/**
	 * Plugin for output.
	 *
	 * @param string $file Plugin file.
	 * @return array<string, mixed>
	 */
	private static function shape( string $file ): array {
		$data  = get_plugins()[ $file ] ?? array();
		$offer = get_site_transient( 'update_plugins' );
		return array(
			'file'             => $file,
			'name'             => (string) ( $data['Name'] ?? $file ),
			'version'          => (string) ( $data['Version'] ?? '' ),
			'active'           => is_plugin_active( $file ),
			'update'           => is_object( $offer ) && isset( $offer->response[ $file ]->new_version ) ? (string) $offer->response[ $file ]->new_version : null,
			'requires_wp'      => (string) ( $data['RequiresWP'] ?? '' ),
			'requires_php'     => (string) ( $data['RequiresPHP'] ?? '' ),
			'requires_plugins' => array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $data['RequiresPlugins'] ?? '' ) ) ) ) ),
		);
	}
}
