<?php
/**
 * Safety layer: audit log, redaction, snapshots, rate limit, cleanup, connections (test matrix F1, F9–F11).
 *
 * Runs after LifecycleTest/McpEndpointTest/OAuthTest (file order).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Admin\Connections;
use Kuduy\Mcp\Admin\ConnectionsPage;
use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Audit\Redactor;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Lifecycle\Cleanup;
use Kuduy\Mcp\Lifecycle\Deactivator;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolRegistry;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

final class SafetyTest extends TestCase {

	private static int $admin;

	private static int $subscriber;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin      = self::user( 'administrator' );
		self::$subscriber = self::user( 'subscriber' );
		add_action( 'kuduy_mcp_register_tools', array( self::class, 'register_tools' ) );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	protected function tearDown(): void {
		remove_all_filters( 'kuduy_mcp_rate_limit' );
	}

	private static function user( string $role ): int {
		$id = wp_insert_user(
			array(
				'user_login' => 'safety_' . $role . '_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => $role,
			)
		);
		return is_wp_error( $id ) ? 0 : $id;
	}

	public static function register_tools( ToolRegistry $registry ): void {
		$registry->register(
			self::tool(
				'test-secrets',
				'read',
				false,
				static fn( array $in ) => array(
					'db_password' => 's3cret-db',
					'note'        => 'ok ' . ( $in['text'] ?? '' ),
					'nested'      => array( 'api_key' => 'k-123' ),
					'leak'        => 'token kmcp_at_abcdefghijklmnop',
				)
			)
		);
		$registry->register(
			self::tool(
				'test-admin-only2',
				'manage_options',
				false,
				static fn() => array( 'ok' => true )
			)
		);
		$registry->register(
			new class() implements Tool, Snapshottable {
				public function name(): string {
					return 'test-set-option';
				}
				public function title(): string {
					return 'Set option';
				}
				public function description(): string {
					return 'test';
				}
				public function input_schema(): array {
					return array(
						'type'       => 'object',
						'properties' => array( 'value' => array( 'type' => 'string' ) ),
						'required'   => array( 'value' ),
					);
				}
				public function output_schema(): array {
					return array( 'type' => 'object' );
				}
				public function annotations(): array {
					return array( 'destructiveHint' => true );
				}
				public function scope(): string {
					return 'kuduy:write';
				}
				public function capability( array $input ): string {
					return 'manage_options';
				}
				public function is_available(): bool|string {
					return true;
				}
				public function execute( array $input ): array {
					update_option( 'kuduy_test_option', $input['value'] );
					return array( 'value' => $input['value'] );
				}
				public function snapshot( array $input ): array {
					return array(
						'object_type' => 'option',
						'object_id'   => 'kuduy_test_option',
						'payload'     => array( 'value' => get_option( 'kuduy_test_option' ) ),
					);
				}
				public function restore( array $payload ): void {
					update_option( 'kuduy_test_option', $payload['value'] );
				}
			}
		);
	}

	/**
	 * Simple test tool.
	 *
	 * @param string   $name        Name.
	 * @param string   $cap         Capability.
	 * @param bool     $destructive Destructive hint.
	 * @param callable $run         Body.
	 */
	private static function tool( string $name, string $cap, bool $destructive, callable $run ): Tool {
		return new class( $name, $cap, $destructive, $run ) implements Tool {
			/**
			 * Constructor.
			 *
			 * @param string   $n Name.
			 * @param string   $c Capability.
			 * @param bool     $d Destructive.
			 * @param callable $r Body.
			 */
			public function __construct( private string $n, private string $c, private bool $d, private $r ) {}
			public function name(): string {
				return $this->n;
			}
			public function title(): string {
				return $this->n;
			}
			public function description(): string {
				return 'test';
			}
			public function input_schema(): array {
				return array(
					'type'       => 'object',
					'properties' => array(
						'text'     => array( 'type' => 'string' ),
						'password' => array( 'type' => 'string' ),
						'nested'   => array( 'type' => 'object' ),
					),
				);
			}
			public function output_schema(): array {
				return array( 'type' => 'object' );
			}
			public function annotations(): array {
				return array( 'destructiveHint' => $this->d );
			}
			public function scope(): string {
				return 'kuduy:read';
			}
			public function capability( array $input ): string {
				return $this->c;
			}
			public function is_available(): bool|string {
				return true;
			}
			public function execute( array $input ): array {
				return ( $this->r )( $input );
			}
		};
	}

	/**
	 * Modern tools/call through the REST server.
	 *
	 * @param string               $tool Tool.
	 * @param array<string, mixed> $args Arguments.
	 */
	private function call( string $tool, array $args = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/kuduy-mcp/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'MCP-Protocol-Version', '2026-07-28' );
		$request->set_header( 'Mcp-Method', 'tools/call' );
		$request->set_header( 'Mcp-Name', $tool );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/call',
					'params'  => array(
						'name'      => $tool,
						'arguments' => $args ? $args : new \stdClass(),
						'_meta'     => array(
							'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
							'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
						),
					),
				)
			)
		);
		return rest_ensure_response( rest_get_server()->dispatch( $request ) );
	}

	public function test_redactor_masks_secret_keys_and_values(): void {
		$out = Redactor::redact(
			array(
				'user_pass'     => 'p',
				'Password'      => 'p',
				'api_key'       => 'k',
				'client_secret' => 's',
				'AUTH_KEY'      => 'a',
				'title'         => 'Hello',
				'list'          => array( 'Bearer abc.def', 'plain', '$wp$2y$10$abcdefghijklmnopqrstuv' ),
				'deep'          => array( 'refresh_token' => 'kmcp_rt_x' ),
				'keywords'      => 'seo keywords stay',
			)
		);

		foreach ( array( 'user_pass', 'Password', 'api_key', 'client_secret', 'AUTH_KEY' ) as $key ) {
			$this->assertSame( Redactor::MASK, $out[ $key ], $key );
		}
		$this->assertSame( 'Hello', $out['title'] );
		$this->assertSame( array( Redactor::MASK, 'plain', Redactor::MASK ), $out['list'] );
		$this->assertSame( Redactor::MASK, $out['deep']['refresh_token'] );
		$this->assertSame( 'seo keywords stay', $out['keywords'] );
	}

	/**
	 * WooCommerce secrets (W2): gateway credentials, REST API keys, order keys, payment tokens, and any settings
	 * field of type `password` whatever its name (WooCommerce returns gateway settings as `{ id, type, value }`).
	 */
	public function test_redactor_masks_woocommerce_secrets(): void {
		$out = Redactor::redact(
			array(
				'api_signature'   => 'AFcWxV21C7fd0v3bYYYRCpSSRl31A',
				'consumer_key'    => 'ck_0123456789abcdef',
				'order_key'       => 'wc_order_AbC123',
				'cart_hash'       => 'd41d8cd98f00b204e9800998ecf8427e',
				'payment_token'   => 'tok_visa',
				'settings'        => array(
					'merchant_pin' => array(
						'id'    => 'merchant_pin',
						'type'  => 'password',
						'value' => '4711',
					),
					'title'        => array(
						'id'    => 'title',
						'type'  => 'text',
						'value' => 'Card',
					),
				),
				'billing_country' => 'DE',
			)
		);

		foreach ( array( 'api_signature', 'consumer_key', 'order_key', 'cart_hash', 'payment_token' ) as $key ) {
			$this->assertSame( Redactor::MASK, $out[ $key ], $key );
		}
		$this->assertSame( Redactor::MASK, $out['settings']['merchant_pin']['value'] );
		$this->assertSame( 'password', $out['settings']['merchant_pin']['type'] );
		$this->assertSame( 'Card', $out['settings']['title']['value'] );
		$this->assertSame( 'DE', $out['billing_country'] );
	}

	public function test_every_call_is_audited_with_redacted_arguments(): void {
		$response = $this->call(
			'test-secrets',
			array(
				'text'     => 'hi',
				'password' => 'hunter2',
				'nested'   => array( 'api_key' => 'abc-key' ),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$row = AuditLog::latest( 1 )[0];
		$this->assertSame( 'test-secrets', $row['tool'] );
		$this->assertSame( 'ok', $row['result'] );
		$this->assertSame( self::$admin, (int) $row['user_id'] );
		$this->assertSame( 'wordpress-login', $row['client_id'] );
		$this->assertGreaterThanOrEqual( 0, (int) $row['duration_ms'] );
		$this->assertStringContainsString( 'hi', $row['args_redacted'] );
		$this->assertStringNotContainsString( 'hunter2', $row['args_redacted'] );
		$this->assertStringNotContainsString( 'abc-key', $row['args_redacted'] );
	}

	public function test_tool_results_are_redacted(): void {
		$raw = (string) wp_json_encode( $this->call( 'test-secrets', array( 'text' => 'x' ) )->get_data() );

		$this->assertStringContainsString( 'ok x', $raw );
		foreach ( array( 's3cret-db', 'k-123', 'kmcp_at_abcdefghijklmnop' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $raw, $secret );
		}
	}

	public function test_denied_and_invalid_calls_are_audited(): void {
		wp_set_current_user( self::$subscriber );
		$this->call( 'test-admin-only2' );
		$denied = AuditLog::latest( 1 )[0];
		$this->assertSame( 'denied', $denied['result'] );
		$this->assertSame( 'forbidden', $denied['error_code'] );

		wp_set_current_user( self::$admin );
		$this->call( 'test-set-option', array( 'wrong' => 1 ) );
		$invalid = AuditLog::latest( 1 )[0];
		$this->assertSame( 'error', $invalid['result'] );
		$this->assertSame( 'invalid_arguments', $invalid['error_code'] );
	}

	public function test_destructive_tool_snapshots_and_restores_once(): void {
		update_option( 'kuduy_test_option', 'before' );

		$this->assertSame( 200, $this->call( 'test-set-option', array( 'value' => 'after' ) )->get_status() );
		$this->assertSame( 'after', get_option( 'kuduy_test_option' ) );
		$snapshot_id = (int) AuditLog::latest( 1 )[0]['snapshot_id'];
		$this->assertGreaterThan( 0, $snapshot_id );

		$list = $this->call( 'kuduy-snapshot-list' )->get_data()['result']['structuredContent']['snapshots'];
		$this->assertContains( $snapshot_id, array_column( $list, 'id' ) );

		$restore = $this->call( 'kuduy-snapshot-restore', array( 'snapshot_id' => $snapshot_id ) )->get_data()['result'];
		$this->assertFalse( $restore['isError'], (string) wp_json_encode( $restore ) );
		$this->assertSame( 'before', get_option( 'kuduy_test_option' ) );

		$again = $this->call( 'kuduy-snapshot-restore', array( 'snapshot_id' => $snapshot_id ) )->get_data()['result'];
		$this->assertTrue( $again['isError'], 'a snapshot restores only once' );
	}

	public function test_users_cannot_restore_others_snapshots(): void {
		update_option( 'kuduy_test_option', 'x' );
		$this->call( 'test-set-option', array( 'value' => 'y' ) );
		$snapshot_id = (int) AuditLog::latest( 1 )[0]['snapshot_id'];

		wp_set_current_user( self::$subscriber );
		$result = $this->call( 'kuduy-snapshot-restore', array( 'snapshot_id' => $snapshot_id ) )->get_data()['result'];

		$this->assertTrue( $result['isError'] );
		$this->assertSame( 'y', get_option( 'kuduy_test_option' ) );
	}

	public function test_rate_limit_returns_429_with_retry_after(): void {
		wp_set_current_user( self::user( 'editor' ) );
		add_filter( 'kuduy_mcp_rate_limit', static fn() => 3 );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( 200, $this->call( 'kuduy-site-info' )->get_status(), "call {$i}" );
		}
		$limited = $this->call( 'kuduy-site-info' );

		$this->assertSame( 429, $limited->get_status() );
		$this->assertGreaterThan( 0, (int) ( $limited->get_headers()['Retry-After'] ?? 0 ) );
		$this->assertArrayHasKey( 'error', $limited->get_data() );
	}

	public function test_cleanup_is_scheduled_and_purges_old_rows_only(): void {
		global $wpdb;
		$this->assertNotFalse( wp_next_scheduled( Cleanup::HOOK ) );
		$old  = gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS );
		$now  = gmdate( 'Y-m-d H:i:s' );
		$base = array(
			'user_id' => 1,
			'tool'    => 'kuduy-site-info',
			'result'  => 'ok',
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( $wpdb->prefix . 'kuduy_audit_log', $base + array( 'ts' => $old ) );
		$old_id = (int) $wpdb->insert_id;
		$wpdb->insert( $wpdb->prefix . 'kuduy_audit_log', $base + array( 'ts' => $now ) );
		$new_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			$wpdb->prefix . 'kuduy_oauth_tokens',
			array(
				'token_hash' => hash( 'sha256', 'old-token' ),
				'type'       => 'access',
				'family_id'  => str_repeat( 'f', 32 ),
				'client_id'  => 'c',
				'user_id'    => 1,
				'scope'      => 'kuduy:read',
				'resource'   => 'r',
				'expires_at' => $old,
			)
		);

		Cleanup::run();

		$ids = array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}kuduy_audit_log" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertNotContains( $old_id, $ids );
		$this->assertContains( $new_id, $ids );
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}kuduy_oauth_tokens WHERE token_hash = %s", hash( 'sha256', 'old-token' ) ) ) );
		// phpcs:enable
	}

	public function test_deactivation_clears_cron(): void {
		Deactivator::run();
		$this->assertFalse( wp_next_scheduled( Cleanup::HOOK ) );

		Plugin::boot();
		$this->assertNotFalse( wp_next_scheduled( Cleanup::HOOK ), 'boot reschedules' );
	}

	public function test_connections_list_and_revoke(): void {
		global $wpdb;
		$family = str_repeat( 'a', 32 );
		$row    = array(
			'type'       => 'refresh',
			'family_id'  => $family,
			'client_id'  => 'https://client.example/oauth/meta.json',
			'user_id'    => self::$subscriber,
			'scope'      => 'kuduy:read',
			'resource'   => 'r',
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
		);
		$wpdb->insert( $wpdb->prefix . 'kuduy_oauth_tokens', $row + array( 'token_hash' => hash( 'sha256', 'conn-rt' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$own = Connections::for_user( get_userdata( self::$subscriber ) );
		$this->assertContains( $family, array_column( $own, 'family_id' ) );
		$this->assertContains( $family, array_column( Connections::for_user( get_userdata( self::$admin ) ), 'family_id' ), 'admins see all' );
		$this->assertNotContains( $family, array_column( Connections::for_user( get_userdata( self::user( 'subscriber' ) ) ), 'family_id' ) );

		$this->assertFalse( Connections::revoke( $family, get_userdata( self::user( 'subscriber' ) ) ), 'others cannot revoke' );
		$this->assertTrue( Connections::revoke( $family, get_userdata( self::$subscriber ) ) );
		$this->assertNotContains( $family, array_column( Connections::for_user( get_userdata( self::$subscriber ) ), 'family_id' ) );
	}

	public function test_admin_page_shows_mcp_url_and_activity(): void {
		ob_start();
		ConnectionsPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( esc_html( rest_url( 'kuduy-mcp/v1/mcp' ) ), $html );
		$this->assertStringContainsString( 'test-secrets', $html );
		$this->assertStringNotContainsString( 'hunter2', $html );
	}
}
