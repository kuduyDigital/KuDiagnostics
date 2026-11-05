<?php
/**
 * Tools: kuduy-site-health, kuduy-cron-list, kuduy-rewrite-check, kuduy-rewrite-flush, kuduy-hooks-inspect
 * (one class, five modes).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Read-only diagnostics for site managers (manage_options). Site Health runs only core's in-process checks:
 * the ones that make loopback or external HTTP requests are skipped so a call cannot hang.
 */
final class Diagnostics implements Tool {

	/**
	 * Core Site Health checks that run in-process (rest_availability makes a loopback request and is left out).
	 */
	private const HEALTH_TESTS = array(
		'wordpress_version',
		'plugin_version',
		'theme_version',
		'php_version',
		'php_extensions',
		'php_default_timezone',
		'php_sessions',
		'sql_server',
		'ssl_support',
		'scheduled_events',
		'http_requests',
		'is_in_debug_mode',
		'file_uploads',
		'plugin_theme_auto_updates',
		'update_temp_backup_writable',
		'available_updates_disk_space',
		'autoloaded_options',
		'insecure_registration',
		'search_engine_visibility',
		'opcode_cache',
	);

	/**
	 * Mode.
	 *
	 * @param string $mode health | cron | rewrite | rewrite-flush | hooks.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return match ( $this->mode ) {
			'health' => 'kuduy-site-health',
			'cron'   => 'kuduy-cron-list',
			'hooks'   => 'kuduy-hooks-inspect',
			'rewrite' => 'kuduy-rewrite-check',
			default   => 'kuduy-' . $this->mode,
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'health'        => __( 'Site Health check', 'kuduy-mcp' ),
			'cron'          => __( 'List scheduled tasks', 'kuduy-mcp' ),
			'rewrite'       => __( 'Check permalinks', 'kuduy-mcp' ),
			'rewrite-flush' => __( 'Refresh permalinks', 'kuduy-mcp' ),
			default         => __( 'Inspect a hook', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'health'        => 'Runs WordPress Site Health checks that work without HTTP requests (versions, PHP extensions, database, HTTPS, scheduled events, debug mode, uploads, auto-updates, disk space, autoloaded options, registration, search visibility, opcode cache) and returns a short environment summary (versions, memory, upload size, object cache, cron).',
			'cron'          => 'Lists scheduled tasks (WP-Cron): hook, next run (UTC), schedule, whether it is overdue, and whether WP-Cron is disabled or locked.',
			'rewrite'       => 'Checks permalinks: structure, whether pretty URLs work, number of rewrite rules, and whether the REST API and the KUDUY MCP route are registered. Use when pages return 404 or sign-in fails.',
			'rewrite-flush' => 'Rebuilds the rewrite rules (like saving Settings > Permalinks). Fixes many 404 errors after adding post types or changing permalinks. Does not change the permalink structure.',
			default         => 'Lists every callback on one action or filter hook with its priority and where it is defined (function, class method, closure with file and line). Use it to find which plugin changes a behaviour.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$props = 'hooks' === $this->mode ? array(
			'hook' => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 200,
			),
		) : array();
		return array(
			'type'                 => 'object',
			'properties'           => $props,
			'required'             => $props ? array( 'hook' ) : array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		$strings = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);
		$props   = match ( $this->mode ) {
			'health'  => array(
				'checks'      => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'test'        => array( 'type' => 'string' ),
							'label'       => array( 'type' => 'string' ),
							'status'      => array(
								'type' => 'string',
								'enum' => array( 'good', 'recommended', 'critical' ),
							),
							'description' => array( 'type' => 'string' ),
						),
						'required'   => array( 'test', 'label', 'status' ),
					),
				),
				'counts'      => array( 'type' => 'object' ),
				'environment' => array( 'type' => 'object' ),
			),
			'cron'    => array(
				'disabled' => array( 'type' => 'boolean' ),
				'locked'   => array( 'type' => 'boolean' ),
				'events'   => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'hook'         => array( 'type' => 'string' ),
							'next_run_gmt' => array( 'type' => 'string' ),
							'schedule'     => array( 'type' => 'string' ),
							'overdue'      => array( 'type' => 'boolean' ),
							'args'         => array( 'type' => 'integer' ),
						),
					),
				),
			),
			'hooks'   => array(
				'hook'      => array( 'type' => 'string' ),
				'callbacks' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'priority' => array( 'type' => 'integer' ),
							'callback' => array( 'type' => 'string' ),
							'file'     => array( 'type' => array( 'string', 'null' ) ),
							'line'     => array( 'type' => array( 'integer', 'null' ) ),
							'source'   => array( 'type' => 'string' ),
						),
					),
				),
			),
			default   => array(
				'permalink_structure' => array( 'type' => 'string' ),
				'pretty'              => array( 'type' => 'boolean' ),
				'rules'               => array( 'type' => 'integer' ),
				'rest_url'            => array( 'type' => 'string' ),
				'kuduy_route'         => array( 'type' => 'boolean' ),
				'server_rewrite'      => array( 'type' => 'boolean' ),
				'problems'            => $strings,
			),
		};//end match
		return array(
			'type'       => 'object',
			'properties' => $props,
			'required'   => array_keys( $props ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return 'rewrite-flush' === $this->mode ? array(
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		) : Content::read_only();
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'rewrite-flush' === $this->mode ? 'kuduy:admin' : 'kuduy:read';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'manage_options';
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
	public function execute( array $input ): array {
		return match ( $this->mode ) {
			'health'        => self::health(),
			'cron'          => self::cron(),
			'hooks'         => self::hooks( (string) $input['hook'] ),
			'rewrite-flush' => self::flush(),
			default         => self::rewrite(),
		};
	}

	/**
	 * Site Health in-process checks plus an environment summary.
	 *
	 * @return array<string, mixed>
	 */
	private static function health(): array {
		require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		$health = \WP_Site_Health::get_instance();
		$checks = array();
		$counts = array(
			'good'        => 0,
			'recommended' => 0,
			'critical'    => 0,
		);
		foreach ( self::HEALTH_TESTS as $test ) {
			$method = 'get_test_' . $test;
			if ( ! method_exists( $health, $method ) ) {
				continue;
			}
			$result = $health->$method();
			if ( ! isset( $counts[ $result['status'] ?? '' ] ) ) {
				continue;
			}
			++$counts[ $result['status'] ];
			$checks[] = array(
				'test'        => $test,
				'label'       => wp_strip_all_tags( (string) $result['label'] ),
				'status'      => (string) $result['status'],
				'description' => trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) ( $result['description'] ?? '' ) ) ) ?? '' ),
			);
		}
		global $wpdb;
		return array(
			'checks'      => $checks,
			'counts'      => $counts,
			'environment' => array(
				'wordpress'         => get_bloginfo( 'version' ),
				'php'               => PHP_VERSION,
				'database'          => $wpdb->db_server_info(),
				'memory_limit'      => (string) ini_get( 'memory_limit' ),
				'wp_memory_limit'   => defined( 'WP_MEMORY_LIMIT' ) ? (string) constant( 'WP_MEMORY_LIMIT' ) : '',
				'max_upload'        => size_format( wp_max_upload_size() ),
				'https'             => is_ssl(),
				'object_cache'      => wp_using_ext_object_cache(),
				'opcache'           => function_exists( 'opcache_get_status' ) && is_array( @opcache_get_status( false ) ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- restricted opcache API warns.
				'debug'             => WP_DEBUG,
				'cron_disabled'     => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'environment_type'  => wp_get_environment_type(),
				'file_mods_allowed' => wp_is_file_mod_allowed( 'kuduy_mcp' ),
			),
		);
	}

	/**
	 * Scheduled events.
	 *
	 * @return array<string, mixed>
	 */
	private static function cron(): array {
		$events = array();
		foreach ( (array) _get_cron_array() as $time => $hooks ) {
			foreach ( (array) $hooks as $hook => $instances ) {
				foreach ( (array) $instances as $event ) {
					$events[] = array(
						'hook'         => (string) $hook,
						'next_run_gmt' => gmdate( 'Y-m-d H:i:s', (int) $time ),
						'schedule'     => $event['schedule'] ? (string) $event['schedule'] : 'once',
						// Allow a minute of slack before calling an event overdue.
						'overdue'      => (int) $time < time() - MINUTE_IN_SECONDS,
						// Arguments can hold private data; only their number is shown.
						'args'         => count( $event['args'] ),
					);
				}
			}
		}
		$lock = get_transient( 'doing_cron' );
		return array(
			'disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'locked'   => false !== $lock && (float) $lock > microtime( true ) - ( defined( 'WP_CRON_LOCK_TIMEOUT' ) ? (int) constant( 'WP_CRON_LOCK_TIMEOUT' ) : MINUTE_IN_SECONDS ),
			'events'   => $events,
		);
	}

	/**
	 * Permalink and route health.
	 *
	 * @return array<string, mixed>
	 */
	private static function rewrite(): array {
		global $wp_rewrite;
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		$structure = (string) get_option( 'permalink_structure' );
		$rules     = is_object( $wp_rewrite ) ? (array) $wp_rewrite->wp_rewrite_rules() : array();
		$routes    = rest_get_server()->get_routes();
		$problems  = array();
		if ( '' === $structure ) {
			$problems[] = 'Plain permalinks: Claude\'s sign-in needs pretty permalinks (Settings > Permalinks, any option except Plain).';
		} elseif ( ! $rules ) {
			$problems[] = 'Pretty permalinks are set but no rewrite rules are stored: run kuduy-rewrite-flush.';
		}
		if ( ! got_url_rewrite() ) {
			$problems[] = 'The web server does not report URL rewriting; pretty URLs may return 404 (check .htaccess or the Nginx rules).';
		}
		if ( ! isset( $routes['/kuduy-mcp/v1/mcp'] ) ) {
			$problems[] = 'The KUDUY MCP REST route is not registered.';
		}
		return array(
			'permalink_structure' => $structure,
			'pretty'              => '' !== $structure,
			'rules'               => count( $rules ),
			'rest_url'            => rest_url(),
			'kuduy_route'         => isset( $routes['/kuduy-mcp/v1/mcp'] ),
			'server_rewrite'      => got_url_rewrite(),
			'problems'            => $problems,
		);
	}

	/**
	 * Rebuild rewrite rules (soft: the .htaccess file is not rewritten).
	 *
	 * @return array<string, mixed>
	 */
	private static function flush(): array {
		flush_rewrite_rules( false );
		return self::rewrite();
	}

	/**
	 * Callbacks on a hook.
	 *
	 * @param string $hook Hook name.
	 * @return array<string, mixed>
	 * @throws ToolError When nothing is attached.
	 */
	private static function hooks( string $hook ): array {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof \WP_Hook ) {
			throw new ToolError( 'Nothing is attached to this hook in the current request (it may be added only in wp-admin or on the front end).' );
		}
		$out = array();
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( (array) $callbacks as $callback ) {
				$out[] = array( 'priority' => (int) $priority ) + self::describe( $callback['function'] );
			}
		}
		return array(
			'hook'      => $hook,
			'callbacks' => $out,
		);
	}

	/**
	 * Name, file, line and source (core, plugin, theme, mu-plugin) of a callback.
	 *
	 * @param mixed $callback Callback.
	 * @return array{callback:string, file:?string, line:?int, source:string}
	 */
	private static function describe( mixed $callback ): array {
		$name       = 'unknown';
		$reflection = null;
		try {
			if ( is_string( $callback ) && str_contains( $callback, '::' ) ) {
				$name       = $callback;
				$reflection = new \ReflectionMethod( $callback );
			} elseif ( is_string( $callback ) && function_exists( $callback ) ) {
				$name       = $callback . '()';
				$reflection = new \ReflectionFunction( $callback );
			} elseif ( is_array( $callback ) && 2 === count( $callback ) && is_string( $callback[1] ) && ( is_object( $callback[0] ) || is_string( $callback[0] ) ) ) {
				$class      = is_object( $callback[0] ) ? get_class( $callback[0] ) : $callback[0];
				$name       = $class . ( is_object( $callback[0] ) ? '->' : '::' ) . $callback[1] . '()';
				$reflection = new \ReflectionMethod( $class, $callback[1] );
			} elseif ( $callback instanceof \Closure ) {
				$name       = 'closure';
				$reflection = new \ReflectionFunction( $callback );
			} elseif ( is_object( $callback ) ) {
				$name       = get_class( $callback ) . '->__invoke()';
				$reflection = new \ReflectionMethod( $callback, '__invoke' );
			}
		} catch ( \ReflectionException $e ) {
			$reflection = null;
		}//end try
		$file = $reflection ? $reflection->getFileName() : false;
		return array(
			'callback' => $name,
			'file'     => false !== $file ? self::relative( $file ) : null,
			'line'     => $reflection && false !== $reflection->getStartLine() ? $reflection->getStartLine() : null,
			'source'   => false !== $file ? self::source( $file ) : 'internal',
		);
	}

	/**
	 * Path relative to the WordPress root (never the server's full path).
	 *
	 * @param string $file File.
	 */
	private static function relative( string $file ): string {
		$file = wp_normalize_path( $file );
		$root = wp_normalize_path( ABSPATH );
		return str_starts_with( $file, $root ) ? substr( $file, strlen( $root ) ) : basename( $file );
	}

	/**
	 * Which kind of code a file belongs to.
	 *
	 * @param string $file File.
	 */
	private static function source( string $file ): string {
		$file = wp_normalize_path( $file );
		foreach ( array(
			'mu-plugin: ' => WPMU_PLUGIN_DIR,
			'plugin: '    => WP_PLUGIN_DIR,
			'theme: '     => get_theme_root(),
		) as $label => $dir ) {
			$dir = wp_normalize_path( $dir ) . '/';
			if ( str_starts_with( $file, $dir ) ) {
				return $label . strtok( substr( $file, strlen( $dir ) ), '/' );
			}
		}
		return 'core';
	}
}
