<?php
/**
 * Tools: kuduy-dev-files, kuduy-dev-file-read, kuduy-dev-search, kuduy-dev-log.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Dev;

use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Read-only file access for developers (D21 A): inside the WordPress folder and wp-content only, secret files
 * refused, credential-looking values masked, sizes capped. Site administrators (manage_options) with the admin
 * scope only.
 */
final class DevFiles implements Tool {

	private const MAX_READ    = 524288;
	private const MAX_LINES   = 2000;
	private const MAX_LIST    = 500;
	private const MAX_HITS    = 200;
	private const MAX_SCANNED = 5000;
	private const SEARCHABLE  = array( 'php', 'js', 'mjs', 'css', 'scss', 'json', 'txt', 'md', 'html', 'twig', 'xml', 'yml', 'yaml', 'po', 'pot', 'svg' );

	/**
	 * Mode.
	 *
	 * @param string $mode list | read | search | log.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return match ( $this->mode ) {
			'list'   => 'kuduy-dev-files',
			'read'   => 'kuduy-dev-file-read',
			'search' => 'kuduy-dev-search',
			default  => 'kuduy-dev-log',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'list'   => __( 'List files', 'kuduy-mcp' ),
			'read'   => __( 'Read a file', 'kuduy-mcp' ),
			'search' => __( 'Search files', 'kuduy-mcp' ),
			default  => __( 'Read the debug log', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		$secret = ' Secret files (wp-config.php, .env, keys, certificates, .htpasswd, database dumps, .git) are never read; credential-looking values are masked.';
		return match ( $this->mode ) {
			'list'   => 'Lists a folder inside wp-content (root "content", default) or the WordPress folder (root "WordPress"): name, type, size, modified time. At most 500 entries.' . $secret,
			'read'   => 'Reads a text file inside wp-content or the WordPress folder: from start_line (1-based), at most 2000 lines and 512 KB.' . $secret,
			'search' => 'Finds a text (literal, case-insensitive) in files of wp-content (or a sub-folder via path): file, line number and line. Text-like files only, at most 200 matches.' . $secret,
			default  => 'Returns the last lines of the PHP debug log (wp-content/debug.log or WP_DEBUG_LOG\'s file) when logging is on; credential-looking values masked.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$path       = array(
			'type'      => 'string',
			'maxLength' => 1000,
		);
		$properties = array();
		$required   = array();
		switch ( $this->mode ) {
			case 'list':
				$properties = array(
					'root' => DevPaths::root_input(),
					'path' => $path,
				);
				break;
			case 'read':
				$properties = array(
					'root'       => DevPaths::root_input(),
					'path'       => $path + array( 'minLength' => 1 ),
					'start_line' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'max_lines'  => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => self::MAX_LINES,
					),
				);
				$required   = array( 'path' );
				break;
			case 'search':
				$properties = array(
					'text' => array(
						'type'      => 'string',
						'minLength' => 2,
						'maxLength' => 200,
					),
					'path' => $path,
				);
				$required   = array( 'text' );
				break;
			default:
				$properties = array(
					'lines' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 1000,
						'default' => 200,
					),
				);
		}//end switch
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		$list = array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		);
		return match ( $this->mode ) {
			'list'   => array(
				'type'       => 'object',
				'properties' => array(
					'path'    => array( 'type' => 'string' ),
					'entries' => $list,
				),
				'required'   => array( 'path', 'entries' ),
			),
			'read'   => array(
				'type'       => 'object',
				'properties' => array(
					'path'        => array( 'type' => 'string' ),
					'start_line'  => array( 'type' => 'integer' ),
					'total_lines' => array( 'type' => 'integer' ),
					'content'     => array( 'type' => 'string' ),
					'truncated'   => array( 'type' => 'boolean' ),
				),
				'required'   => array( 'path', 'content', 'total_lines' ),
			),
			'search' => array(
				'type'       => 'object',
				'properties' => array(
					'matches'   => $list,
					'truncated' => array( 'type' => 'boolean' ),
				),
				'required'   => array( 'matches', 'truncated' ),
			),
			default  => array(
				'type'       => 'object',
				'properties' => array(
					'enabled' => array( 'type' => 'boolean' ),
					'file'    => array( 'type' => 'string' ),
					'lines'   => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				'required'   => array( 'enabled', 'lines' ),
			),
		};//end match
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return Content::read_only();
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:admin';
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
	 * @throws ToolError When a path is outside the roots, secret, missing or not text.
	 */
	public function execute( array $input ): array {
		return match ( $this->mode ) {
			'list'   => self::list_folder( (string) ( $input['root'] ?? 'content' ), (string) ( $input['path'] ?? '' ) ),
			'read'   => self::read( $input ),
			'search' => self::search( (string) $input['text'], (string) ( $input['path'] ?? '' ) ),
			default  => self::log( (int) ( $input['lines'] ?? 200 ) ),
		};
	}

	/**
	 * List a folder.
	 *
	 * @param string $root Root.
	 * @param string $rel  Path.
	 * @return array<string, mixed>
	 * @throws ToolError When it is not a folder.
	 */
	private static function list_folder( string $root, string $rel ): array {
		$dir = DevPaths::existing( $root, $rel );
		if ( ! is_dir( $dir ) ) {
			throw new ToolError( 'Not a folder: ' . esc_html( $rel ) );
		}
		$entries = array();
		foreach ( (array) scandir( $dir ) as $name ) {
			$name = (string) $name;
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path = $dir . '/' . $name;
			if ( DevPaths::is_secret( $path ) ) {
				continue;
			}
			$entries[] = array(
				'name'     => $name,
				'type'     => is_dir( $path ) ? 'folder' : 'file',
				'size'     => is_file( $path ) ? (int) filesize( $path ) : null,
				'modified' => gmdate( 'c', (int) filemtime( $path ) ),
			);
			if ( count( $entries ) >= self::MAX_LIST ) {
				break;
			}
		}
		return array(
			'path'    => DevPaths::relative( $dir ),
			'entries' => $entries,
		);
	}

	/**
	 * Read a text file.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When it is not a readable text file.
	 */
	private static function read( array $input ): array {
		$path = DevPaths::existing( (string) ( $input['root'] ?? 'content' ), (string) $input['path'] );
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new ToolError( 'Not a readable file: ' . esc_html( (string) $input['path'] ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, size-capped.
		$data = (string) file_get_contents( $path, false, null, 0, self::MAX_READ + 1 );
		if ( DevText::binary( $data ) ) {
			throw new ToolError( 'This is a binary file; only text files can be read.' );
		}
		$truncated = strlen( $data ) > self::MAX_READ;
		$lines     = explode( "\n", substr( $data, 0, self::MAX_READ ) );
		$start     = max( 1, (int) ( $input['start_line'] ?? 1 ) );
		$max       = min( self::MAX_LINES, (int) ( $input['max_lines'] ?? self::MAX_LINES ) );
		$slice     = array_slice( $lines, $start - 1, $max );
		return array(
			'path'        => DevPaths::relative( $path ),
			'start_line'  => $start,
			'total_lines' => count( $lines ),
			'content'     => DevText::mask( implode( "\n", $slice ) ),
			'truncated'   => $truncated || $start - 1 + count( $slice ) < count( $lines ),
		);
	}

	/**
	 * Search text in wp-content.
	 *
	 * @param string $text Text.
	 * @param string $rel  Sub-folder of wp-content.
	 * @return array<string, mixed>
	 */
	private static function search( string $text, string $rel ): array {
		$dir     = DevPaths::existing( 'content', $rel );
		$matches = array();
		$scanned = 0;
		$cut     = false;
		// A path that names one file searches just that file (it used to fail: a directory iterator cannot open a file).
		$files = is_file( $dir )
			? ( DevPaths::is_secret( $dir ) ? array() : array( new \SplFileInfo( $dir ) ) )
			: new \RecursiveIteratorIterator(
				new \RecursiveCallbackFilterIterator(
					new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
					static fn( \SplFileInfo $f ) => ! DevPaths::is_secret( $f->getPathname() ) && ! in_array( $f->getFilename(), array( 'node_modules', 'vendor', 'cache', 'uploads' ), true )
				)
			);
		foreach ( $files as $file ) {
			if ( ! $file instanceof \SplFileInfo || ! $file->isFile() || $file->getSize() > 1048576 || ! in_array( strtolower( $file->getExtension() ), self::SEARCHABLE, true ) ) {
				continue;
			}
			if ( ++$scanned > self::MAX_SCANNED ) {
				$cut = true;
				break;
			}
			$handle = fopen( $file->getPathname(), 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- read-only, local.
			if ( false === $handle ) {
				continue;
			}
			$number = 0;
			while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- line loop.
				++$number;
				if ( false !== stripos( $line, $text ) ) {
					$matches[] = array(
						'file' => DevPaths::relative( $file->getPathname() ),
						'line' => $number,
						'text' => DevText::mask( mb_substr( trim( $line ), 0, 300 ) ),
					);
					if ( count( $matches ) >= self::MAX_HITS ) {
						$cut = true;
						break;
					}
				}
			}
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- read-only, local.
			if ( $cut ) {
				break;
			}
		}//end foreach
		return array(
			'matches'   => $matches,
			'truncated' => $cut,
		);
	}

	/**
	 * Tail of the debug log.
	 *
	 * @param int $count Lines.
	 * @return array<string, mixed>
	 */
	private static function log( int $count ): array {
		$setting = defined( 'WP_DEBUG_LOG' ) ? constant( 'WP_DEBUG_LOG' ) : false;
		if ( ! $setting ) {
			return array(
				'enabled' => false,
				'lines'   => array(),
			);
		}
		// wp_debug_mode() points PHP's error_log at WP_DEBUG_LOG's file (a path, or wp-content/debug.log for true).
		$file = (string) ini_get( 'error_log' );
		$file = '' !== $file ? $file : WP_CONTENT_DIR . '/debug.log';
		$real = realpath( $file );
		if ( false === $real || ! is_readable( $real ) ) {
			return array(
				'enabled' => true,
				'file'    => DevPaths::relative( $file ),
				'lines'   => array(),
			);
		}
		$size   = (int) filesize( $real );
		$handle = fopen( $real, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- read-only, local.
		$data   = '';
		if ( false !== $handle ) {
			fseek( $handle, max( 0, $size - self::MAX_READ ) );
			$data = (string) stream_get_contents( $handle );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- read-only, local.
		}
		$lines = array_slice( array_values( array_filter( explode( "\n", $data ), static fn( string $l ): bool => '' !== $l ) ), -$count );
		return array(
			'enabled' => true,
			'file'    => DevPaths::relative( $real ),
			'lines'   => array_map( array( DevText::class, 'mask' ), $lines ),
		);
	}
}
