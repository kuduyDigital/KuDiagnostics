<?php
/**
 * Phase 4: diagnostics (Site Health, cron, permalinks, hooks) and the permission sweeps P3 (what a subscriber can
 * see) and P4 (no secrets in any response or audit row) (test matrix C8, P3, P4).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class CoreDiagnosticsTest extends TestCase {

	use CallsTools;

	private static int $admin;

	private static int $editor;

	private static int $subscriber;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		update_option( 'permalink_structure', '/%postname%/' );
		$GLOBALS['wp_rewrite']->init();
		self::$admin      = self::make_user( 'administrator' );
		self::$editor     = self::make_user( 'editor' );
		self::$subscriber = self::make_user( 'subscriber' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	public function test_diagnostics_need_manage_options(): void {
		wp_set_current_user( self::$editor );
		foreach ( array( 'kuduy-site-health', 'kuduy-cron-list', 'kuduy-rewrite-check', 'kuduy-rewrite-flush' ) as $tool ) {
			$this->fails( $tool, array(), $tool . ' is for site managers' );
		}
		$this->fails( 'kuduy-hooks-inspect', array( 'hook' => 'init' ), 'hooks are for site managers' );
	}

	public function test_site_health_runs_in_process_checks_only(): void {
		$health = $this->ok( 'kuduy-site-health' );

		$tests = array_column( $health['checks'], 'test' );
		$this->assertContains( 'php_version', $tests );
		$this->assertNotContains( 'rest_availability', $tests, 'loopback checks are skipped' );
		$this->assertSame( count( $tests ), array_sum( $health['counts'] ) );
		$this->assertSame( get_bloginfo( 'version' ), $health['environment']['wordpress'] );
	}

	public function test_cron_lists_events_without_their_arguments(): void {
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'kuduy_test_event', array( 'sk_live_TOPSECRET' ) );

		$cron  = $this->ok( 'kuduy-cron-list' );
		$hooks = array_column( $cron['events'], null, 'hook' );
		$this->assertSame( 1, $hooks['kuduy_test_event']['args'] );
		$this->assertSame( 'once', $hooks['kuduy_test_event']['schedule'] );
		$this->assertSame( 'daily', $hooks['kuduy_mcp_cleanup']['schedule'] ?? null );
		$this->assertStringNotContainsString( 'TOPSECRET', (string) wp_json_encode( $cron ) );
		wp_clear_scheduled_hook( 'kuduy_test_event', array( 'sk_live_TOPSECRET' ) );
	}

	public function test_rewrite_check_and_flush(): void {
		$check = $this->ok( 'kuduy-rewrite-check' );
		$this->assertTrue( $check['pretty'] );
		$this->assertTrue( $check['kuduy_route'] );

		$flushed = $this->ok( 'kuduy-rewrite-flush' );
		$this->assertGreaterThan( 0, $flushed['rules'] );
	}

	public function test_hooks_inspect_names_callbacks_and_their_source(): void {
		$hook = $this->ok( 'kuduy-hooks-inspect', array( 'hook' => 'determine_current_user' ) );
		$ours = array_values( array_filter( $hook['callbacks'], static fn( $c ) => str_contains( $c['callback'], 'TokenAuth' ) ) );

		$this->assertNotEmpty( $ours );
		$this->assertSame( 30, $ours[0]['priority'] );
		$this->assertSame( 'plugin: kuduy-mcp', $ours[0]['source'] );
		$this->assertStringStartsWith( 'wp-content/plugins/kuduy-mcp/src/OAuth/TokenAuth.php', (string) $ours[0]['file'] );
		$this->fails( 'kuduy-hooks-inspect', array( 'hook' => 'kuduy_nothing_here' ), 'empty hook' );
	}

	public function test_p3_subscriber_sees_only_tools_they_can_use(): void {
		wp_set_current_user( self::$subscriber );
		$request = new \WP_REST_Request( 'POST', '/kuduy-mcp/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'MCP-Protocol-Version', '2026-07-28' );
		$request->set_header( 'Mcp-Method', 'tools/list' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/list',
					'params'  => array( '_meta' => array( 'io.modelcontextprotocol/protocolVersion' => '2026-07-28' ) ),
				)
			)
		);
		$names = array_column( rest_get_server()->dispatch( $request )->get_data()['result']['tools'], 'name' );

		foreach ( array( 'kuduy-site-info', 'kuduy-post-list', 'kuduy-post-get', 'kuduy-user-get', 'kuduy-comment-reply' ) as $visible ) {
			$this->assertContains( $visible, $names );
		}
		foreach ( array( 'kuduy-plugin-install', 'kuduy-theme-switch', 'kuduy-user-create', 'kuduy-user-list', 'kuduy-settings-update', 'kuduy-site-health', 'kuduy-post-create', 'kuduy-menu-create', 'kuduy-term-create' ) as $hidden ) {
			$this->assertNotContains( $hidden, $names, $hidden . ' must be hidden from subscribers' );
		}
	}

	public function test_p4_no_secrets_in_responses_or_audit_rows(): void {
		[ $app_password, $item ] = \WP_Application_Passwords::create_new_application_password( self::$admin, array( 'name' => 'kuduy-p4' ) );
		$post                    = (int) wp_insert_post(
			array(
				'post_title'  => 'Kuduy P4',
				'post_status' => 'publish',
			)
		);
		add_post_meta( $post, 'leaked_token', 'kmcp_at_abcdefghijklmnop' );

		$outputs = array();
		foreach ( array(
			array( 'kuduy-site-info', array() ),
			array( 'kuduy-site-health', array() ),
			array( 'kuduy-cron-list', array() ),
			array( 'kuduy-settings-get', array() ),
			array( 'kuduy-user-list', array( 'per_page' => 100 ) ),
			array( 'kuduy-user-get', array( 'id' => self::$admin ) ),
			array( 'kuduy-role-list', array() ),
			array( 'kuduy-plugin-list', array() ),
			array( 'kuduy-post-meta-get', array( 'post_id' => $post ) ),
			array( 'kuduy-rewrite-check', array() ),
			array( 'kuduy-hooks-inspect', array( 'hook' => 'init' ) ),
		) as [ $tool, $args ] ) {
			$outputs[] = (string) wp_json_encode( $this->ok( $tool, $args ) );
		}
		$outputs[] = (string) wp_json_encode( AuditLog::latest( 50 ) );
		$haystack  = implode( "\n", $outputs );

		$secrets = array(
			'application password'      => $app_password,
			'application password hash' => (string) ( $item['password'] ?? '' ),
			'user password hash'        => get_userdata( self::$admin )->user_pass,
			'access token in meta'      => 'kmcp_at_abcdefghijklmnop',
		);
		foreach ( array( 'DB_PASSWORD', 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $constant ) {
			if ( defined( $constant ) ) {
				$secrets[ $constant ] = (string) constant( $constant );
			}
		}
		foreach ( $secrets as $label => $secret ) {
			if ( strlen( $secret ) >= 8 ) {
				$this->assertStringNotContainsString( $secret, $haystack, $label . ' leaked (P4)' );
			}
		}
		$this->assertStringContainsString( '[redacted]', $haystack, 'the token in post meta is masked, not dropped silently' );
	}
}
