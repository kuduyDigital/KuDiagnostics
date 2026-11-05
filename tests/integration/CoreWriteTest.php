<?php
/**
 * Phase 4 content writes: create, update, trash, undo — and who may do what (test matrix C1, C4, C5, P1, F10).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Mcp\ToolRegistry;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

final class CoreWriteTest extends TestCase {

	private static int $admin;

	private static int $editor;

	private static int $author;

	private static int $contributor;

	private static int $subscriber;

	private static int $cat;

	private static int $image;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin       = self::user( 'administrator' );
		self::$editor      = self::user( 'editor' );
		self::$author      = self::user( 'author' );
		self::$contributor = self::user( 'contributor' );
		self::$subscriber  = self::user( 'subscriber' );
		$cat               = wp_insert_term( 'Kuduy Write Cat', 'category' );
		self::$cat         = is_array( $cat ) ? (int) $cat['term_id'] : 0;
		self::$image       = (int) wp_insert_attachment(
			array(
				'post_title'     => 'Kuduy image',
				'post_mime_type' => 'image/png',
				'post_status'    => 'inherit',
			),
			'kuduy-test.png'
		);
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	private static function user( string $role ): int {
		$id = wp_insert_user(
			array(
				'user_login' => 'write_' . $role . '_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => $role,
			)
		);
		return is_wp_error( $id ) ? 0 : $id;
	}

	/**
	 * Call a tool through the MCP endpoint; returns the CallToolResult.
	 *
	 * @param string               $tool Tool.
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private function call( string $tool, array $args = array() ): array {
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
						'_meta'     => array( 'io.modelcontextprotocol/protocolVersion' => '2026-07-28' ),
					),
				)
			)
		);
		$data = rest_ensure_response( rest_get_server()->dispatch( $request ) )->get_data();
		$this->assertIsArray( $data['result'] ?? null, (string) wp_json_encode( $data ) );
		return json_decode( (string) wp_json_encode( $data['result'] ), true );
	}

	/**
	 * Successful call's structured result.
	 *
	 * @param string               $tool Tool.
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private function ok( string $tool, array $args ): array {
		$result = $this->call( $tool, $args );
		$this->assertFalse( $result['isError'], $tool . ': ' . (string) wp_json_encode( $result ) );
		$valid = rest_validate_value_from_schema( $result['structuredContent'], ToolRegistry::create()->get( $tool )->output_schema(), $tool );
		$this->assertTrue( true === $valid, $tool . ' output schema: ' . ( is_wp_error( $valid ) ? $valid->get_error_message() : '' ) );
		return $result['structuredContent'];
	}

	public function test_create_draft_with_terms_and_featured_image(): void {
		wp_set_current_user( self::$author );

		$post = $this->ok(
			'kuduy-post-create',
			array(
				'title'          => 'Kuduy created',
				'content'        => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
				'terms'          => array( 'category' => array( self::$cat ) ),
				'featured_media' => self::$image,
			)
		);

		$this->assertSame( 'draft', $post['status'] );
		$this->assertSame( self::$author, $post['author'] );
		$this->assertContains( self::$cat, wp_get_object_terms( $post['id'], 'category', array( 'fields' => 'ids' ) ) );
		$this->assertSame( self::$image, (int) get_post_thumbnail_id( $post['id'] ) );
	}

	public function test_contributor_can_draft_but_not_publish_and_html_is_filtered(): void {
		wp_set_current_user( self::$contributor );

		$this->assertTrue(
			$this->call(
				'kuduy-post-create',
				array(
					'title'  => 'Kuduy contrib publish',
					'status' => 'publish',
				)
			)['isError'],
			'contributors cannot publish'
		);
		$draft = $this->ok(
			'kuduy-post-create',
			array(
				'title'   => 'Kuduy contrib draft',
				'content' => '<p>ok</p><script>alert(1)</script>',
			)
		);
		$this->assertStringNotContainsString( '<script>', get_post( $draft['id'] )->post_content, 'kses applies without unfiltered_html' );

		wp_set_current_user( self::$subscriber );
		$this->assertTrue( $this->call( 'kuduy-post-create', array( 'title' => 'Kuduy subscriber' ) )['isError'] );
	}

	public function test_schedule_needs_a_future_date(): void {
		$this->assertTrue(
			$this->call(
				'kuduy-post-create',
				array(
					'title'  => 'Kuduy no date',
					'status' => 'future',
				)
			)['isError']
		);
		$post = $this->ok(
			'kuduy-post-create',
			array(
				'title'    => 'Kuduy scheduled',
				'status'   => 'future',
				'date_gmt' => gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS ),
			)
		);
		$this->assertSame( 'future', $post['status'] );
	}

	public function test_update_checks_edit_post_per_object(): void {
		wp_set_current_user( self::$editor );
		$editor_post = $this->ok( 'kuduy-post-create', array( 'title' => 'Kuduy editor own' ) );

		wp_set_current_user( self::$author );
		$denied = $this->call(
			'kuduy-post-update',
			array(
				'id'    => $editor_post['id'],
				'title' => 'Hijacked',
			)
		);
		$this->assertTrue( $denied['isError'], 'author cannot edit other people\'s posts (P1)' );
		$this->assertSame( 'Kuduy editor own', get_post( $editor_post['id'] )->post_title );

		wp_set_current_user( self::$editor );
		$this->assertSame(
			'Edited by editor',
			$this->ok(
				'kuduy-post-update',
				array(
					'id'    => $editor_post['id'],
					'title' => 'Edited by editor',
				)
			)['title']
		);
	}

	public function test_update_snapshots_and_undo_restores_fields_and_terms(): void {
		$post = $this->ok(
			'kuduy-post-create',
			array(
				'title'   => 'Kuduy before',
				'content' => 'Old body',
				'status'  => 'publish',
			)
		);

		$updated = $this->ok(
			'kuduy-post-update',
			array(
				'id'      => $post['id'],
				'title'   => 'Kuduy after',
				'content' => 'New body',
				'status'  => 'draft',
				'terms'   => array( 'category' => array( self::$cat ) ),
			)
		);
		$this->assertSame( 'draft', $updated['status'] );
		$this->assertGreaterThan( 0, $updated['_snapshot_id'] ?? 0 );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $updated['_snapshot_id'] ) );
		$back = get_post( $post['id'] );
		$this->assertSame( 'Kuduy before', $back->post_title );
		$this->assertSame( 'Old body', $back->post_content );
		$this->assertSame( 'publish', $back->post_status );
		$this->assertNotContains( self::$cat, wp_get_object_terms( $post['id'], 'category', array( 'fields' => 'ids' ) ) );
	}

	public function test_bad_terms_and_images_are_refused_before_writing(): void {
		$post = $this->ok( 'kuduy-post-create', array( 'title' => 'Kuduy terms target' ) );

		$cases = array(
			array( 'terms' => array( 'nope' => array( 1 ) ) ),
			array( 'terms' => array( 'category' => array( 999999 ) ) ),
			array( 'featured_media' => $post['id'] ),
		);
		foreach ( $cases as $extra ) {
			$result = $this->call(
				'kuduy-post-update',
				array(
					'id'    => $post['id'],
					'title' => 'Should not change',
				) + $extra
			);
			$this->assertTrue( $result['isError'], (string) wp_json_encode( $extra ) );
		}
		$this->assertSame( 'Kuduy terms target', get_post( $post['id'] )->post_title, 'nothing written when extras are invalid' );
	}

	public function test_elementor_page_content_is_protected(): void {
		$page = $this->ok(
			'kuduy-post-create',
			array(
				'post_type' => 'page',
				'title'     => 'Kuduy elementor page',
			)
		);
		update_post_meta( $page['id'], '_elementor_edit_mode', 'builder' );

		$this->assertTrue(
			$this->call(
				'kuduy-post-update',
				array(
					'id'      => $page['id'],
					'content' => 'would break the layout',
				)
			)['isError']
		);
		$this->ok(
			'kuduy-post-update',
			array(
				'id'    => $page['id'],
				'title' => 'Renamed is fine',
			)
		);
	}

	public function test_trash_and_undo_restores_previous_status(): void {
		$post = $this->ok(
			'kuduy-post-create',
			array(
				'title'  => 'Kuduy to trash',
				'status' => 'publish',
			)
		);

		wp_set_current_user( self::$contributor );
		$this->assertTrue( $this->call( 'kuduy-post-trash', array( 'id' => $post['id'] ) )['isError'], 'contributor cannot delete others\' posts' );

		wp_set_current_user( self::$admin );
		$trashed = $this->ok( 'kuduy-post-trash', array( 'id' => $post['id'] ) );
		$this->assertSame( 'trash', $trashed['status'] );
		$this->assertTrue( $this->call( 'kuduy-post-trash', array( 'id' => $post['id'] ) )['isError'], 'already in trash' );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $trashed['_snapshot_id'] ) );
		$this->assertSame( 'publish', get_post_status( $post['id'] ), 'undo puts the previous status back' );
	}
}
