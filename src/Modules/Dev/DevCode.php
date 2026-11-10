<?php
/**
 * Tools: kuduy-dev-file-write, kuduy-dev-php (decision D4, D21 A).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Dev;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Packages;

/**
 * Code tools exist only when wp-config.php defines KUDUY_MCP_ALLOW_CODE_EXEC as true; they need the separate
 * kuduy:code scope (offered only to users with manage_options and unfiltered_html / edit_plugins), and the user must
 * have edit_plugins (so DISALLOW_FILE_EDIT turns them off) and manage_options. File writes go only to plugin and
 * theme folders and the KuDiagnostics sandbox (DevPaths::writable()), PHP must parse, files of an active plugin or theme are
 * confirm-first, and every write has an undo. PHP snippets run with a time limit, output and errors captured; a fatal
 * error is written to the audit log by a shutdown handler.
 */
final class DevCode implements Tool, Snapshottable {

	public const CONSTANT = 'KUDUY_MCP_ALLOW_CODE_EXEC';

	private const MAX_FILE   = 1048576;
	private const MAX_OUTPUT = 65536;
	private const SECONDS    = 30;

	/**
	 * Mode.
	 *
	 * @param string $mode write | php.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * Whether D4's constant is set.
	 */
	public static function enabled(): bool {
		return defined( self::CONSTANT ) && true === constant( self::CONSTANT );
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'write' === $this->mode ? 'kuduy-dev-file-write' : 'kuduy-dev-php';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'write' === $this->mode ? __( 'Write a file', 'kuduy-mcp' ) : __( 'Run PHP', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'write' === $this->mode
			? 'Creates or replaces a text file in a plugin folder, a theme folder or wp-content/kuduy-sandbox (root "content", path e.g. "plugins/my-plugin/my-plugin.php"). PHP files must parse. KuDiagnostics itself, must-use plugins, WordPress core and secret files are never changed. Changing a file of an active plugin or theme can break the site, so the first call only reports it (done: false): ask the user, then call again with confirm: true. Snapshot first; undo with kuduy-snapshot-restore.'
			: 'Runs a PHP snippet inside WordPress as your user (no opening <?php tag; use return to give back a value, echo output is captured). Time limit 30 seconds. Changes the code makes are not undone by KuDiagnostics. Every run is in the audit log, including fatal errors.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$properties = 'write' === $this->mode
			? array(
				'root'    => DevPaths::root_input(),
				'path'    => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 1000,
				),
				'content' => array(
					'type'      => 'string',
					'maxLength' => self::MAX_FILE,
				),
				'confirm' => Packages::confirm_input(),
			)
			: array(
				'code' => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 100000,
				),
			);
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => 'write' === $this->mode ? array( 'path', 'content' ) : array( 'code' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return 'write' === $this->mode
			? array(
				'type'       => 'object',
				'properties' => array(
					'path'         => array( 'type' => 'string' ),
					'done'         => array( 'type' => 'boolean' ),
					'created'      => array( 'type' => 'boolean' ),
					'bytes'        => array( 'type' => 'integer' ),
					'impact'       => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'_snapshot_id' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'path', 'done', 'impact' ),
			)
			: array(
				'type'       => 'object',
				'properties' => array(
					'ok'          => array( 'type' => 'boolean' ),
					'result'      => array(),
					'output'      => array( 'type' => 'string' ),
					'errors'      => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'duration_ms' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'ok', 'output', 'errors' ),
			);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => 'write' === $this->mode,
			'openWorldHint'   => 'php' === $this->mode,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:code';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'edit_plugins';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return self::enabled() ? true : 'Code tools are off. A site administrator can turn them on with define( \'KUDUY_MCP_ALLOW_CODE_EXEC\', true ); in wp-config.php';
	}

	/**
	 * Refuse users without manage_options (edit_plugins is checked by the executor).
	 *
	 * @throws ToolError When the user is not a site administrator.
	 */
	private static function assert_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new ToolError( 'Code tools need a site administrator (manage_options).' );
		}
	}

	/**
	 * Refuse PHP that does not parse.
	 *
	 * @param string $path    Target path.
	 * @param string $content Content.
	 * @throws ToolError When it does not parse.
	 */
	private static function assert_parses( string $path, string $content ): void {
		if ( 'php' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			return;
		}
		try {
			$tokens = token_get_all( $content, TOKEN_PARSE );
			if ( ! $tokens && '' !== trim( $content ) ) {
				throw new ToolError( 'The PHP could not be read. Nothing was written.' );
			}
		} catch ( \ParseError $e ) {
			throw new ToolError( 'The PHP does not parse (line ' . (int) $e->getLine() . ': ' . esc_html( $e->getMessage() ) . '). Nothing was written.' );
		}
	}

	/**
	 * What writing this file would put at risk (confirm-first).
	 *
	 * @param string $path Target path.
	 * @return list<string>
	 */
	private static function impact( string $path ): array {
		$owner = DevPaths::active_owner( $path );
		if ( null === $owner ) {
			return array();
		}
		[ $kind, $dir ] = explode( ':', $owner, 2 );
		return array( 'This file belongs to the active ' . $kind . ' "' . $dir . '": a mistake in it can break the site for visitors and admins (WordPress recovery mode may then pause the ' . $kind . ').' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the target or content is refused.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'write' !== $this->mode ) {
			return null;
		}
		self::assert_admin();
		$path = DevPaths::writable( (string) ( $input['root'] ?? 'content' ), (string) $input['path'] );
		self::assert_parses( $path, (string) $input['content'] );
		if ( self::impact( $path ) && empty( $input['confirm'] ) ) {
			return null;
		}
		$exists = is_file( $path );
		return array(
			'object_type' => 'file',
			'object_id'   => DevPaths::relative( $path ),
			'payload'     => array(
				'path'    => $path,
				'existed' => $exists,
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary-safe snapshot of the previous file (local, size-capped).
				'content' => $exists ? base64_encode( (string) file_get_contents( $path ) ) : null,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the file cannot be restored.
	 */
	public function restore( array $payload ): void {
		$path = (string) ( $payload['path'] ?? '' );
		$root = DevPaths::roots()['content'];
		if ( '' === $path || ( ! DevPaths::inside( $path, $root ) && ! DevPaths::inside( $path, DevPaths::roots()['wordpress'] ) ) ) {
			throw new ToolError( 'The saved path is outside the site.' );
		}
		if ( empty( $payload['existed'] ) ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- restoring the binary-safe snapshot of a file KuDiagnostics wrote.
		if ( false === file_put_contents( $path, (string) base64_decode( (string) $payload['content'], true ) ) ) {
			throw new ToolError( 'The previous content could not be written back.' );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the request is refused or the write fails.
	 */
	public function execute( array $input ): array {
		self::assert_admin();
		return 'write' === $this->mode ? self::write( $input ) : self::run( (string) $input['code'] );
	}

	/**
	 * Write a file.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the write is refused or fails.
	 */
	private static function write( array $input ): array {
		$path    = DevPaths::writable( (string) ( $input['root'] ?? 'content' ), (string) $input['path'] );
		$content = (string) $input['content'];
		self::assert_parses( $path, $content );
		AuditLog::target( 'file', DevPaths::relative( $path ) );
		$impact = self::impact( $path );
		$out    = array(
			'path'   => DevPaths::relative( $path ),
			'done'   => false,
			'impact' => $impact,
		);
		if ( $impact && empty( $input['confirm'] ) ) {
			return $out;
		}
		$created = ! is_file( $path );
		if ( ! is_dir( dirname( $path ) ) && ! wp_mkdir_p( dirname( $path ) ) ) {
			throw new ToolError( 'The folder could not be created.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- approved folder, D4.
		$bytes = file_put_contents( $path, $content, LOCK_EX );
		if ( false === $bytes ) {
			throw new ToolError( 'WordPress could not write the file (check folder permissions).' );
		}
		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $path, true );
		}
		return array(
			'path'    => DevPaths::relative( $path ),
			'done'    => true,
			'created' => $created,
			'bytes'   => (int) $bytes,
			'impact'  => $impact,
		);
	}

	/**
	 * Run a PHP snippet.
	 *
	 * @param string $code Code (no opening tag).
	 * @return array<string, mixed>
	 */
	private static function run( string $code ): array {
		$code   = (string) preg_replace( '/^\s*<\?php\s*/i', '', $code );
		$errors = array();
		$start  = hrtime( true );
		$marker = 'kuduy_mcp_php_' . wp_generate_password( 8, false );
		update_option( $marker, array( 'user' => get_current_user_id() ), false );
		register_shutdown_function(
			static function () use ( $marker ): void {
				if ( false === get_option( $marker ) ) {
					return;
				}
				delete_option( $marker );
				$error = error_get_last();
				if ( is_array( $error ) && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
					AuditLog::record( 'kuduy-dev-php', array( 'fatal' => DevText::mask( (string) $error['message'] ) ), 'error', 'fatal', 0, null );
				}
			}
		);
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- collect warnings of the snippet.
		set_error_handler(
			static function ( int $no, string $message, string $file, int $line ) use ( &$errors ): bool {
				$errors[] = DevText::mask( $message ) . ' (line ' . $line . ')';
				return true;
			}
		);
		// The snippet gets SECONDS; the request's own limit is put back afterwards (found 2026-11-10: leaving 30 s in
		// place stopped a longer request — the test suite — that ran a snippet early on).
		$previous_limit = (int) ini_get( 'max_execution_time' );
		set_time_limit( self::SECONDS ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- D4 time limit.
		ob_start();
		$ok     = true;
		$result = null;
		try {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- D4: code execution, behind the constant, kuduy:code, edit_plugins and manage_options.
			$result = ( static fn() => eval( $code ) )();
		} catch ( \Throwable $e ) {
			$ok       = false;
			$errors[] = get_class( $e ) . ': ' . DevText::mask( $e->getMessage() ) . ' (line ' . $e->getLine() . ')';
		}
		$output = (string) ob_get_clean();
		set_time_limit( $previous_limit ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- restore the request's limit (0 = none).
		restore_error_handler();
		delete_option( $marker );
		$encoded = json_decode( (string) wp_json_encode( $result ), true );
		return array(
			'ok'          => $ok,
			'result'      => null === $encoded && null !== $result ? ( is_scalar( $result ) ? (string) $result : get_debug_type( $result ) ) : $encoded,
			'output'      => DevText::mask( mb_substr( $output, 0, self::MAX_OUTPUT ) ),
			'errors'      => $errors,
			'duration_ms' => (int) ( ( hrtime( true ) - $start ) / 1000000 ),
		);
	}
}
