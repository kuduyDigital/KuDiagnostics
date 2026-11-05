<?php
/**
 * Phase 4 read tools: post types, posts, single post, terms — and who may see what (test matrix C1, C2, P1, P3).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

final class CoreContentTest extends TestCase {

	private static int $admin;

	private static int $editor;

	private static int $author;

	private static int $subscriber;

	/**
	 * Post ids by label.
	 *
	 * @var array<string, int>
	 */
	private static array $p = array();

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin      = self::user( 'administrator' );
		self::$editor     = self::user( 'editor' );
		self::$author     = self::user( 'author' );
		self::$subscriber = self::user( 'subscriber' );

		register_taxonomy(
			'kuduy_internal',
			'post',
			array(
				'public'  => false,
				'show_ui' => true,
			)
		);
		register_post_type( 'kuduy_hidden', array( 'show_ui' => false ) );

		$cat     = wp_insert_term( 'Kuduy News', 'category' );
		$cat_id  = is_array( $cat ) ? (int) $cat['term_id'] : 0;
		self::$p = array(
			'published'    => self::post( self::$author, 'publish', 'Kuduy published post', array( 'post_category' => array( $cat_id ) ) ),
			'author_draft' => self::post( self::$author, 'draft', 'Kuduy author draft' ),
			'editor_draft' => self::post( self::$editor, 'draft', 'Kuduy editor draft' ),
			'private'      => self::post( self::$editor, 'private', 'Kuduy private post' ),
			'protected'    => self::post(
				self::$editor,
				'publish',
				'Kuduy protected post',
				array(
					'post_password' => 'sesame-123',
					'post_content'  => 'Members only text',
				)
			),
			'hidden'       => self::post( self::$admin, 'publish', 'Kuduy hidden type', array( 'post_type' => 'kuduy_hidden' ) ),
		);
		wp_insert_term( 'Kuduy secret term', 'kuduy_internal' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	private static function user( string $role ): int {
		$id = wp_insert_user(
			array(
				'user_login' => 'content_' . $role . '_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => $role,
			)
		);
		return is_wp_error( $id ) ? 0 : $id;
	}

	/**
	 * Insert a post.
	 *
	 * @param int                  $author Author.
	 * @param string               $status Status.
	 * @param string               $title  Title.
	 * @param array<string, mixed> $extra  More fields.
	 */
	private static function post( int $author, string $status, string $title, array $extra = array() ): int {
		return (int) wp_insert_post(
			$extra + array(
				'post_author'  => $author,
				'post_status'  => $status,
				'post_title'   => $title,
				'post_content' => 'Body of ' . $title,
			)
		);
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
	 * Titles in a kuduy-post-list result.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return list<string>
	 */
	private function titles( array $args ): array {
		$result = $this->call( 'kuduy-post-list', $args + array( 'search' => 'Kuduy' ) );
		$this->assertFalse( $result['isError'], (string) wp_json_encode( $result ) );
		return array_column( $result['structuredContent']['posts'], 'title' );
	}

	public function test_post_types_lists_content_types_with_permissions(): void {
		wp_set_current_user( self::$author );
		$types = array_column( $this->call( 'kuduy-post-types' )['structuredContent']['post_types'], null, 'name' );

		$this->assertArrayHasKey( 'post', $types );
		$this->assertArrayHasKey( 'page', $types );
		$this->assertArrayNotHasKey( 'kuduy_hidden', $types, 'types without an admin screen are not content types' );
		$this->assertArrayNotHasKey( 'revision', $types );
		$this->assertTrue( $types['post']['can_create'] );
		$this->assertFalse( $types['post']['can_edit_others'] );
		$this->assertContains( 'category', $types['post']['taxonomies'] );
	}

	public function test_published_posts_are_listed_for_any_user(): void {
		wp_set_current_user( self::$subscriber );

		$titles = $this->titles( array() );

		$this->assertContains( 'Kuduy published post', $titles );
		$this->assertNotContains( 'Kuduy author draft', $titles );
		$this->assertNotContains( 'Kuduy private post', $titles );
	}

	public function test_drafts_need_edit_rights_and_authors_only_see_their_own(): void {
		wp_set_current_user( self::$subscriber );
		$denied = $this->call( 'kuduy-post-list', array( 'status' => 'draft' ) );
		$this->assertTrue( $denied['isError'], 'subscriber cannot list drafts' );

		wp_set_current_user( self::$author );
		$author = $this->titles( array( 'status' => 'draft' ) );
		$this->assertContains( 'Kuduy author draft', $author );
		$this->assertNotContains( 'Kuduy editor draft', $author, 'authors do not see other people\'s drafts (P1)' );
		$this->assertNotContains( 'Kuduy editor draft', $this->titles( array( 'status' => 'any' ) ) );

		wp_set_current_user( self::$editor );
		$editor = $this->titles( array( 'status' => 'draft' ) );
		$this->assertContains( 'Kuduy author draft', $editor );
		$this->assertContains( 'Kuduy editor draft', $editor );
	}

	public function test_private_posts_need_read_private_posts(): void {
		wp_set_current_user( self::$author );
		$this->assertTrue( $this->call( 'kuduy-post-list', array( 'status' => 'private' ) )['isError'] );

		wp_set_current_user( self::$editor );
		$this->assertContains( 'Kuduy private post', $this->titles( array( 'status' => 'private' ) ) );
	}

	public function test_list_paging_and_unknown_type(): void {
		$page = $this->call(
			'kuduy-post-list',
			array(
				'search'   => 'Kuduy',
				'status'   => 'any',
				'per_page' => 1,
			)
		)['structuredContent'];
		$this->assertCount( 1, $page['posts'] );
		$this->assertGreaterThanOrEqual( 4, $page['total'] );
		$this->assertSame( $page['total'], $page['total_pages'] );

		$unknown = $this->call( 'kuduy-post-list', array( 'post_type' => 'kuduy_hidden' ) );
		$this->assertTrue( $unknown['isError'] );
		$this->assertStringContainsString( 'Unknown post type', $unknown['content'][0]['text'] );
	}

	public function test_get_returns_content_terms_and_audits_the_post(): void {
		$post = $this->call( 'kuduy-post-get', array( 'id' => self::$p['published'] ) );

		$this->assertFalse( $post['isError'], (string) wp_json_encode( $post ) );
		$data = $post['structuredContent'];
		$this->assertSame( 'Body of Kuduy published post', $data['content'] );
		$this->assertSame( self::$author, $data['author'] );
		$this->assertNotEmpty( $data['terms']['category'] );
		$row = AuditLog::latest( 1 )[0];
		$this->assertSame( 'post', $row['object_type'] );
		$this->assertSame( (string) self::$p['published'], $row['object_id'] );
	}

	public function test_get_checks_read_post_for_that_object(): void {
		wp_set_current_user( self::$subscriber );
		$this->assertFalse( $this->call( 'kuduy-post-get', array( 'id' => self::$p['published'] ) )['isError'] );
		$this->assertTrue( $this->call( 'kuduy-post-get', array( 'id' => self::$p['editor_draft'] ) )['isError'], 'subscriber cannot read a draft' );

		wp_set_current_user( self::$author );
		$this->assertFalse( $this->call( 'kuduy-post-get', array( 'id' => self::$p['author_draft'] ) )['isError'] );
		$this->assertTrue( $this->call( 'kuduy-post-get', array( 'id' => self::$p['editor_draft'] ) )['isError'], 'author cannot read someone else\'s draft' );
	}

	public function test_password_protected_content_and_password_never_leak(): void {
		wp_set_current_user( self::$subscriber );
		$visitor = $this->call( 'kuduy-post-get', array( 'id' => self::$p['protected'] ) );
		$this->assertNull( $visitor['structuredContent']['content'] );
		$this->assertTrue( $visitor['structuredContent']['password_protected'] );

		wp_set_current_user( self::$editor );
		$owner = $this->call( 'kuduy-post-get', array( 'id' => self::$p['protected'] ) );
		$this->assertSame( 'Members only text', $owner['structuredContent']['content'] );
		$this->assertStringNotContainsString( 'sesame-123', (string) wp_json_encode( $owner ), 'post password is never returned (P4)' );
	}

	public function test_get_refuses_non_content_types_and_missing_posts(): void {
		$hidden = $this->call( 'kuduy-post-get', array( 'id' => self::$p['hidden'] ) );
		$this->assertTrue( $hidden['isError'] );
		$this->assertStringContainsString( 'not found', $hidden['content'][0]['text'] );
		$this->assertTrue( $this->call( 'kuduy-post-get', array( 'id' => 999999 ) )['isError'] );
	}

	public function test_terms_public_and_internal_taxonomies(): void {
		wp_set_current_user( self::$subscriber );
		$cats = $this->call( 'kuduy-term-list', array( 'search' => 'Kuduy' ) );
		$this->assertFalse( $cats['isError'] );
		$this->assertContains( 'Kuduy News', array_column( $cats['structuredContent']['terms'], 'name' ) );

		$this->assertTrue( $this->call( 'kuduy-term-list', array( 'taxonomy' => 'kuduy_internal' ) )['isError'], 'non-public taxonomy needs manage_terms' );

		wp_set_current_user( self::$admin );
		$internal = $this->call( 'kuduy-term-list', array( 'taxonomy' => 'kuduy_internal' ) );
		$this->assertContains( 'Kuduy secret term', array_column( $internal['structuredContent']['terms'], 'name' ) );
		$this->assertTrue( $this->call( 'kuduy-term-list', array( 'taxonomy' => 'nope' ) )['isError'] );
	}

	public function test_results_match_output_schemas(): void {
		$tools    = array(
			array( 'kuduy-post-types', array() ),
			array( 'kuduy-post-list', array( 'status' => 'any' ) ),
			array( 'kuduy-post-get', array( 'id' => self::$p['published'] ) ),
			array( 'kuduy-term-list', array() ),
		);
		$registry = \Kuduy\Mcp\Mcp\ToolRegistry::create();
		foreach ( $tools as [ $name, $args ] ) {
			$result = $this->call( $name, $args );
			$valid  = rest_validate_value_from_schema( $result['structuredContent'], $registry->get( $name )->output_schema(), $name );
			$this->assertTrue( true === $valid, $name . ': ' . ( is_wp_error( $valid ) ? $valid->get_error_message() : '' ) );
		}
	}
}
