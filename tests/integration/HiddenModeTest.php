<?php
/**
 * Phase 8 hidden UI mode (test matrix H1–H5, decision D23 A): hiding removes the Tools page and notices and sends
 * direct visits to the dashboard, while the MCP tool list, OAuth metadata and tool calls stay identical; the plugin
 * row stays listed; the WP-CLI command and the option bring the UI back; only administrators can hide it.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Admin\Cli;
use Kuduy\Mcp\Admin\ConnectionsPage;
use Kuduy\Mcp\Admin\HiddenMode;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class HiddenModeTest extends TestCase {

	use CallsTools;

	private static int $admin;
	private static int $editor;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$editor = self::make_user( 'editor' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
		HiddenMode::show();
	}

	protected function tearDown(): void {
		HiddenMode::show();
	}

	/**
	 * Tool names from tools/list through the real endpoint.
	 *
	 * @return list<string>
	 */
	private function tool_names(): array {
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
		$data  = rest_ensure_response( rest_get_server()->dispatch( $request ) )->get_data();
		$names = array_column( (array) ( $data['result']['tools'] ?? array() ), 'name' );
		sort( $names );
		return $names;
	}

	/**
	 * Whether the Tools page is registered for the current user.
	 */
	private static function page_registered(): bool {
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- fresh admin menu for the check.
		$GLOBALS['submenu']           = array();
		$GLOBALS['_registered_pages'] = array();
		// phpcs:enable
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		ConnectionsPage::menu();
		$items = (array) ( $GLOBALS['submenu']['tools.php'] ?? array() );
		return in_array( ConnectionsPage::SLUG, array_column( $items, 2 ), true );
	}

	public function test_hiding_changes_presentation_only(): void {
		$before = $this->tool_names();
		$this->assertNotEmpty( $before );
		$this->assertTrue( self::page_registered() );
		$this->assertNull( HiddenMode::redirect_target( ConnectionsPage::SLUG ) );

		HiddenMode::hide();
		$this->assertTrue( HiddenMode::is_hidden() );
		$this->assertFalse( self::page_registered(), 'The Tools page is gone.' );
		$this->assertSame( admin_url(), HiddenMode::redirect_target( ConnectionsPage::SLUG ), 'Direct visits go to the dashboard.' );
		$this->assertNull( HiddenMode::redirect_target( 'other-page' ) );
		$this->assertSame( $before, $this->tool_names(), 'Same tools while hidden.' );
		$this->assertArrayHasKey( 'name', $this->ok( 'kuduy-site-info' ), 'Tool calls work while hidden.' );

		\Kuduy\Mcp\OAuth\TokenAuth::reset();
		$metadata = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/kuduy-mcp/v1/oauth/protected-resource' ) );
		$this->assertSame( 200, $metadata->get_status(), 'OAuth metadata still served: ' . wp_json_encode( $metadata->get_data() ) );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->assertArrayHasKey( 'kuduy-mcp/kuduy-mcp.php', get_plugins(), 'The plugin row stays in Plugins.' );

		ob_start();
		update_option( 'permalink_structure', '' );
		Plugin::permalink_notice();
		update_option( 'permalink_structure', '/%postname%/' );
		$this->assertSame( '', (string) ob_get_clean(), 'No notices while hidden.' );
	}

	public function test_recovery_by_cli_and_option(): void {
		HiddenMode::hide();
		( new Cli() )->show();
		$this->assertFalse( HiddenMode::is_hidden(), 'wp kuduy ui show' );
		( new Cli() )->hide();
		$this->assertTrue( HiddenMode::is_hidden(), 'wp kuduy ui hide' );
		update_option( HiddenMode::OPTION, '0' );
		$this->assertFalse( HiddenMode::is_hidden(), 'wp option update kuduy_mcp_hidden_mode 0' );
		$this->assertTrue( self::page_registered() );
	}

	public function test_only_administrators_see_the_hide_button(): void {
		ob_start();
		HiddenMode::render_section();
		$this->assertStringContainsString( HiddenMode::ACTION, (string) ob_get_clean() );
		wp_set_current_user( self::$editor );
		ob_start();
		HiddenMode::render_section();
		$this->assertSame( '', (string) ob_get_clean() );
	}
}
