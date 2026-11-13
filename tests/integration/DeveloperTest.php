<?php
/**
 * Phase 7 developer mode (test matrix D1–D4, decisions D4 and D21 A): file list/read/search inside the allowed roots
 * with secret files refused and credentials masked; database schema and capped read-only queries with secrets masked;
 * code tools off without D4's constant, and with it (KUDUY_CODE_EXEC=1) file writes limited to approved folders,
 * parse-checked, confirm-first for active plugins, undoable, and PHP snippets run with output and errors captured.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Mcp\ToolRegistry;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class DeveloperTest extends TestCase {

	use CallsTools;

	private const DIR = 'kuduy-dev-test';

	private static int $admin;
	private static int $editor;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$editor = self::make_user( 'editor' );
		$dir          = WP_CONTENT_DIR . '/' . self::DIR;
		wp_mkdir_p( $dir );
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixtures.
		file_put_contents( $dir . '/sample.php', "<?php\n// kuduy-needle-4711\ndefine( 'MY_SECRET_KEY', 'abc123secret' );\n\$settings = array( 'api_key' => 'xyz-api-789', 'title' => 'Shown' );\n\$db_password = 'pw-456';\n\$user_password = \$_POST['pw'];\n" );
		file_put_contents( $dir . '/.env', "API_TOKEN=env-secret-1 kuduy-needle-4711\n" );
		file_put_contents( $dir . '/blob.txt', "abc\0def" );
		// phpcs:enable
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	public function test_files_list_read_and_refusals(): void {
		$names = array_column( $this->ok( 'kuduy-dev-files', array( 'path' => self::DIR ) )['entries'], 'name' );
		$this->assertContains( 'sample.php', $names );
		$this->assertNotContains( '.env', $names, 'Secret files are not even listed.' );

		$read = $this->ok( 'kuduy-dev-file-read', array( 'path' => self::DIR . '/sample.php' ) );
		$this->assertStringContainsString( 'kuduy-needle-4711', $read['content'] );
		foreach ( array( 'abc123secret', 'xyz-api-789', 'pw-456' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $read['content'] );
		}
		$this->assertStringContainsString( "'title' => 'Shown'", $read['content'] );
		$this->assertStringContainsString( "\$_POST['pw']", $read['content'], 'Code that reads a value is left readable.' );

		$cases = array(
			'wp-config.php'           => array(
				'root' => 'wordpress',
				'path' => 'wp-config.php',
			),
			'.env'                    => array( 'path' => self::DIR . '/.env' ),
			'path traversal'          => array( 'path' => '../wp-config.php' ),
			'absolute path elsewhere' => array( 'path' => '/etc/passwd' ),
			'binary file'             => array( 'path' => self::DIR . '/blob.txt' ),
		);
		foreach ( $cases as $label => $args ) {
			$this->fails( 'kuduy-dev-file-read', $args, $label );
		}
		wp_set_current_user( self::$editor );
		$this->fails( 'kuduy-dev-files', array(), 'editors have no developer tools' );
	}

	public function test_search_skips_secret_files(): void {
		$matches = $this->ok(
			'kuduy-dev-search',
			array(
				'text' => 'kuduy-needle-4711',
				'path' => self::DIR,
			)
		)['matches'];
		$this->assertSame( array( self::DIR . '/sample.php' ), array_values( array_unique( array_column( $matches, 'file' ) ) ) );

		// A path naming one file searches just that file (it crashed before 2026-11-13).
		$one = $this->ok(
			'kuduy-dev-search',
			array(
				'text' => 'kuduy-needle-4711',
				'path' => self::DIR . '/sample.php',
			)
		)['matches'];
		$this->assertSame( array( self::DIR . '/sample.php' ), array_values( array_unique( array_column( $one, 'file' ) ) ) );
	}

	public function test_database_schema_and_masked_queries(): void {
		global $wpdb;
		$schema = $this->ok( 'kuduy-dev-db-schema' );
		$this->assertContains( $wpdb->prefix . 'posts', array_column( $schema['tables'], 'name' ) );

		$users = $this->ok( 'kuduy-dev-db-query', array( 'sql' => 'SELECT ID, user_login, user_pass, user_activation_key FROM {prefix}users LIMIT 5' ) )['rows'];
		$this->assertNotEmpty( $users );
		$this->assertSame( '[redacted]', $users[0]['user_pass'] );
		$this->assertSame( '[redacted]', $users[0]['user_activation_key'] );

		update_option( 'kuduy_api_key', 'opt-secret-55' );
		update_option(
			'kuduy_gateway_settings',
			array(
				'secret_key' => 'sk_test_kuduy',
				'title'      => 'Card',
			)
		);
		$rows = array_column( $this->ok( 'kuduy-dev-db-query', array( 'sql' => "SELECT option_name, option_value FROM {prefix}options WHERE option_name IN ('kuduy_api_key', 'kuduy_gateway_settings')" ) )['rows'], 'option_value', 'option_name' );
		$this->assertSame( '[redacted]', $rows['kuduy_api_key'] );
		$this->assertSame( '[redacted]', $rows['kuduy_gateway_settings']['secret_key'] );
		$this->assertSame( 'Card', $rows['kuduy_gateway_settings']['title'] );
		$bare = $this->ok( 'kuduy-dev-db-query', array( 'sql' => "SELECT option_value FROM {prefix}options WHERE option_name = 'kuduy_api_key'" ) )['rows'];
		$this->assertSame( '[redacted]', $bare[0]['option_value'], 'A value selected without its name is masked.' );

		for ( $i = 0; $i < 205; $i++ ) {
			update_option( 'kuduy_cap_' . $i, (string) $i, false );
		}
		$capped = $this->ok( 'kuduy-dev-db-query', array( 'sql' => "SELECT option_name FROM {prefix}options WHERE option_name LIKE 'kuduy_cap_%' LIMIT 1000" ) );
		$this->assertSame( 200, $capped['count'] );
		$this->assertTrue( $capped['truncated'] );

		foreach ( array(
			"UPDATE {prefix}options SET option_value = 'x' WHERE option_name = 'blogname'",
			'SELECT 1; SELECT 2',
			"SELECT * FROM {prefix}options INTO OUTFILE '/tmp/x'",
			'SELECT SLEEP(1)',
			'SELECT 1 -- comment',
			'DELETE FROM {prefix}posts',
			// Security review 2026-11-10: ways to hide which column a secret comes from.
			"SELECT option_value AS v FROM {prefix}options WHERE option_name = 'kuduy_api_key'",
			"SELECT CONCAT(option_value) FROM {prefix}options WHERE option_name = 'kuduy_api_key'",
			'SELECT ID FROM {prefix}posts UNION SELECT option_value FROM {prefix}options',
		) as $sql ) {
			$this->fails( 'kuduy-dev-db-query', array( 'sql' => $sql ), $sql );
		}
		$this->assertNotSame( 'x', get_option( 'blogname' ) );
	}

	public function test_code_tools_need_the_constant(): void {
		if ( defined( 'KUDUY_MCP_ALLOW_CODE_EXEC' ) && KUDUY_MCP_ALLOW_CODE_EXEC ) {
			$this->markTestSkipped( 'Code tools are on in this run.' );
		}
		// Unavailable tools are not listed or callable at all (the endpoint answers "Unknown tool").
		$this->assertNotTrue( ToolRegistry::create()->get( 'kuduy-dev-php' )->is_available() );
		$this->assertNotTrue( ToolRegistry::create()->get( 'kuduy-dev-file-write' )->is_available() );
		$this->assertSame( 'kuduy:code', ToolRegistry::create()->get( 'kuduy-dev-file-write' )->scope() );
	}

	public function test_code_tools_with_the_constant(): void {
		if ( ! defined( 'KUDUY_MCP_ALLOW_CODE_EXEC' ) || ! KUDUY_MCP_ALLOW_CODE_EXEC ) {
			$this->markTestSkipped( 'Code tools are off in this run (KUDUY_CODE_EXEC=1 turns them on).' );
		}
		$created = $this->ok(
			'kuduy-dev-file-write',
			array(
				'path'    => 'kuduy-sandbox/hello.php',
				'content' => "<?php\nreturn 'v1';\n",
			)
		);
		$this->assertTrue( $created['done'] );
		$this->assertTrue( $created['created'] );
		$changed = $this->ok(
			'kuduy-dev-file-write',
			array(
				'path'    => 'kuduy-sandbox/hello.php',
				'content' => "<?php\nreturn 'v2';\n",
			)
		);
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $changed['_snapshot_id'] ) );
		$this->assertStringContainsString( 'v1', (string) file_get_contents( WP_CONTENT_DIR . '/kuduy-sandbox/hello.php' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test check.
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $created['_snapshot_id'] ) );
		$this->assertFileDoesNotExist( WP_CONTENT_DIR . '/kuduy-sandbox/hello.php', 'Undoing a new file removes it.' );

		$refused = array(
			'PHP that does not parse' => array(
				'path'    => 'kuduy-sandbox/broken.php',
				'content' => '<?php if ( {',
			),
			'KUDUY itself'            => array(
				'path'    => 'plugins/kuduy-mcp/evil.php',
				'content' => '<?php',
			),
			'must-use plugins'        => array(
				'path'    => 'mu-plugins/evil.php',
				'content' => '<?php',
			),
			'loose in plugins'        => array(
				'path'    => 'plugins/evil.php',
				'content' => '<?php',
			),
			'uploads'                 => array(
				'path'    => 'uploads/evil.php',
				'content' => '<?php',
			),
			'secret file name'        => array(
				'path'    => 'kuduy-sandbox/.env',
				'content' => 'X=1',
			),
			'traversal'               => array(
				'path'    => 'kuduy-sandbox/../../wp-config.php',
				'content' => '<?php',
			),
			'binary type'             => array(
				'path'    => 'kuduy-sandbox/tool.exe',
				'content' => 'MZ',
			),
		);
		foreach ( $refused as $label => $args ) {
			$this->fails( 'kuduy-dev-file-write', $args, $label );
		}
		$this->assertFileDoesNotExist( WP_CONTENT_DIR . '/mu-plugins/evil.php' );

		$active = $this->ok(
			'kuduy-dev-file-write',
			array(
				'path'    => 'plugins/elementor/kuduy-note.txt',
				'content' => 'note',
			)
		);
		$this->assertFalse( $active['done'], 'Files of an active plugin are confirm-first.' );
		$this->assertFileDoesNotExist( WP_PLUGIN_DIR . '/elementor/kuduy-note.txt' );

		$run = $this->ok( 'kuduy-dev-php', array( 'code' => 'echo "hi"; return 6 * 7;' ) );
		$this->assertTrue( $run['ok'] );
		$this->assertSame( 42, $run['result'] );
		$this->assertSame( 'hi', $run['output'] );
		$thrown = $this->ok( 'kuduy-dev-php', array( 'code' => 'throw new \RuntimeException( "boom" );' ) );
		$this->assertFalse( $thrown['ok'] );
		$this->assertStringContainsString( 'boom', $thrown['errors'][0] );

		wp_set_current_user( self::$editor );
		$this->fails( 'kuduy-dev-php', array( 'code' => 'return 1;' ), 'editors cannot run code' );
	}
}
