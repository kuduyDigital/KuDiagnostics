<?php
/**
 * Phase 4: terms, comments, revisions and post meta — by role, with undo (test matrix C2, C3, C4, P1, P4).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class CoreEditorialTest extends TestCase {

	use CallsTools;

	private static int $admin;

	private static int $editor;

	private static int $author;

	private static int $contributor;

	private static int $subscriber;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin       = self::make_user( 'administrator' );
		self::$editor      = self::make_user( 'editor' );
		self::$author      = self::make_user( 'author' );
		self::$contributor = self::make_user( 'contributor' );
		self::$subscriber  = self::make_user( 'subscriber' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	/**
	 * A post.
	 *
	 * @param int                  $author Author.
	 * @param array<string, mixed> $extra  Fields.
	 */
	private static function post( int $author, array $extra = array() ): int {
		return (int) wp_insert_post(
			$extra + array(
				'post_author'    => $author,
				'post_status'    => 'publish',
				'post_title'     => 'Kuduy editorial ' . wp_generate_password( 4, false ),
				'post_content'   => 'Body',
				'comment_status' => 'open',
			)
		);
	}

	// ------------------------------------------------------------------ terms.

	public function test_terms_create_update_and_undo(): void {
		wp_set_current_user( self::$author );
		$this->fails( 'kuduy-term-create', array( 'name' => 'Kuduy author cat' ), 'authors cannot manage categories' );

		wp_set_current_user( self::$editor );
		$parent = $this->ok( 'kuduy-term-create', array( 'name' => 'Kuduy parent cat' ) );
		$child  = $this->ok(
			'kuduy-term-create',
			array(
				'name'   => 'Kuduy child cat',
				'parent' => $parent['id'],
			)
		);
		$this->assertSame( $parent['id'], $child['parent'] );
		$this->fails(
			'kuduy-term-update',
			array(
				'id'     => $child['id'],
				'parent' => $child['id'],
			),
			'a term cannot be its own parent'
		);

		$renamed = $this->ok(
			'kuduy-term-update',
			array(
				'id'   => $child['id'],
				'name' => 'Kuduy renamed cat',
			)
		);
		$this->assertSame( 'Kuduy renamed cat', $renamed['name'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $renamed['_snapshot_id'] ) );
		$this->assertSame( 'Kuduy child cat', get_term( $child['id'] )->name );
	}

	public function test_term_delete_keeps_posts_and_undo_reassigns_them(): void {
		$term    = $this->ok(
			'kuduy-term-create',
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Kuduy doomed tag',
			)
		);
		$post_id = self::post( self::$admin );
		wp_set_object_terms( $post_id, array( $term['id'] ), 'post_tag' );

		$deleted = $this->ok( 'kuduy-term-delete', array( 'id' => $term['id'] ) );
		$this->assertTrue( $deleted['deleted'] );
		$this->assertNotNull( get_post( $post_id ), 'posts survive term deletion' );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $deleted['_snapshot_id'] ) );
		$this->assertSame( array( 'Kuduy doomed tag' ), wp_get_object_terms( $post_id, 'post_tag', array( 'fields' => 'names' ) ) );

		$this->fails( 'kuduy-term-delete', array( 'id' => (int) get_option( 'default_category' ) ), 'default category cannot be deleted' );
	}

	// --------------------------------------------------------------- comments.

	public function test_comment_reply_moderation_status_by_role(): void {
		$post_id = self::post( self::$admin );

		wp_set_current_user( self::$subscriber );
		$held = $this->ok(
			'kuduy-comment-reply',
			array(
				'post_id' => $post_id,
				'content' => 'Kuduy subscriber comment',
			)
		);
		$this->assertSame( 'unapproved', $held['status'], 'non-moderators are held for moderation' );
		$this->assertArrayNotHasKey( 'author_email', $held, 'emails only for moderators (P4)' );

		wp_set_current_user( self::$admin );
		$reply = $this->ok(
			'kuduy-comment-reply',
			array(
				'post_id' => $post_id,
				'parent'  => $held['id'],
				'content' => 'Kuduy admin reply',
			)
		);
		$this->assertSame( 'approved', $reply['status'] );
		$this->assertSame( $held['id'], $reply['parent'] );

		$closed = self::post( self::$admin, array( 'comment_status' => 'closed' ) );
		wp_set_current_user( self::$subscriber );
		$this->fails(
			'kuduy-comment-reply',
			array(
				'post_id' => $closed,
				'content' => 'Nope',
			),
			'closed comments'
		);
	}

	public function test_comment_list_hides_held_comments_and_emails_from_non_moderators(): void {
		$post_id  = self::post( self::$admin );
		$approved = (int) wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_content'      => 'Kuduy visible',
				'comment_author'       => 'Visitor',
				'comment_author_email' => 'visitor@example.test',
				'comment_approved'     => 1,
			)
		);
		wp_insert_comment(
			array(
				'comment_post_ID'  => $post_id,
				'comment_content'  => 'Kuduy waiting',
				'comment_author'   => 'Other',
				'comment_approved' => 0,
			)
		);

		wp_set_current_user( self::$subscriber );
		$list = $this->ok( 'kuduy-comment-list', array( 'post_id' => $post_id ) );
		$this->assertSame( array( 'Kuduy visible' ), array_column( $list['comments'], 'content' ) );
		$this->assertStringNotContainsString( 'visitor@example.test', (string) wp_json_encode( $list ) );
		$this->fails(
			'kuduy-comment-list',
			array(
				'post_id' => $post_id,
				'status'  => 'hold',
			),
			'held comments need moderate_comments'
		);
		$draft = self::post( self::$admin, array( 'post_status' => 'draft' ) );
		$this->fails( 'kuduy-comment-list', array( 'post_id' => $draft ), 'comments of an unreadable post' );

		wp_set_current_user( self::$editor );
		$held = $this->ok(
			'kuduy-comment-list',
			array(
				'post_id' => $post_id,
				'status'  => 'all',
			)
		);
		$this->assertCount( 2, $held['comments'] );
		$this->assertContains( 'visitor@example.test', array_column( $held['comments'], 'author_email' ) );
		$this->assertContains( $approved, array_column( $held['comments'], 'id' ) );
	}

	public function test_moderate_needs_edit_comment_and_undo_restores_status(): void {
		$post_id = self::post( self::$admin );
		$comment = (int) wp_insert_comment(
			array(
				'comment_post_ID'  => $post_id,
				'comment_content'  => 'Kuduy moderate me',
				'comment_author'   => 'Visitor',
				'comment_approved' => 0,
			)
		);

		wp_set_current_user( self::$contributor );
		$this->fails(
			'kuduy-comment-moderate',
			array(
				'id'     => $comment,
				'status' => 'approve',
			),
			'contributor cannot moderate comments on someone else\'s post'
		);

		wp_set_current_user( self::$editor );
		$spam = $this->ok(
			'kuduy-comment-moderate',
			array(
				'id'     => $comment,
				'status' => 'spam',
			)
		);
		$this->assertSame( 'spam', $spam['status'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $spam['_snapshot_id'] ) );
		$this->assertSame( 'unapproved', wp_get_comment_status( $comment ) );
	}

	// -------------------------------------------------------------- revisions.

	public function test_revisions_list_restore_and_undo(): void {
		$post_id = self::post( self::$author, array( 'post_content' => 'Version one' ) );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Version two',
			)
		);
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Version three',
			)
		);

		$list = $this->ok(
			'kuduy-revision-list',
			array(
				'post_id'         => $post_id,
				'include_content' => true,
			)
		);
		// Each update stores a revision of the new content; creating the post stores none.
		$old = array_values( array_filter( $list['revisions'], static fn( $r ) => 'Version two' === $r['content'] ) );
		$this->assertNotEmpty( $old, (string) wp_json_encode( $list ) );

		wp_set_current_user( self::$contributor );
		$this->fails( 'kuduy-revision-list', array( 'post_id' => $post_id ), 'contributor cannot see revisions of others\' posts' );

		wp_set_current_user( self::$author );
		$restored = $this->ok( 'kuduy-revision-restore', array( 'revision_id' => $old[0]['id'] ) );
		$this->assertSame( 'Version two', get_post( $post_id )->post_content );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $restored['_snapshot_id'] ) );
		$this->assertSame( 'Version three', get_post( $post_id )->post_content );
	}

	// -------------------------------------------------------------- post meta.

	public function test_meta_set_get_undo_and_protected_keys(): void {
		$post_id = self::post( self::$author );
		add_post_meta( $post_id, '_kuduy_internal', 'secret' );
		add_post_meta( $post_id, 'subtitle', 'Old subtitle' );

		wp_set_current_user( self::$author );
		$set = $this->ok(
			'kuduy-post-meta-set',
			array(
				'post_id' => $post_id,
				'key'     => 'subtitle',
				'value'   => 'New subtitle',
			)
		);
		$this->assertSame( array( 'New subtitle' ), $set['meta']['subtitle'] );
		$this->ok(
			'kuduy-post-meta-set',
			array(
				'post_id' => $post_id,
				'key'     => 'layout',
				'value'   => array(
					'columns' => 2,
					'wide'    => true,
				),
			)
		);

		$all = $this->ok( 'kuduy-post-meta-get', array( 'post_id' => $post_id ) );
		$this->assertSame( 2, $all['meta']['layout'][0]['columns'] );
		$this->assertArrayNotHasKey( '_kuduy_internal', $all['meta'], 'protected keys are hidden' );

		$this->fails(
			'kuduy-post-meta-set',
			array(
				'post_id' => $post_id,
				'key'     => '_kuduy_internal',
				'value'   => 'x',
			),
			'protected keys cannot be written'
		);
		$this->fails(
			'kuduy-post-meta-get',
			array(
				'post_id' => $post_id,
				'key'     => '_kuduy_internal',
			),
			'protected keys cannot be read'
		);
		$this->assertSame( 'secret', get_post_meta( $post_id, '_kuduy_internal', true ) );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $set['_snapshot_id'] ) );
		$this->assertSame( 'Old subtitle', get_post_meta( $post_id, 'subtitle', true ) );

		$this->ok(
			'kuduy-post-meta-set',
			array(
				'post_id' => $post_id,
				'key'     => 'subtitle',
				'value'   => null,
			)
		);
		$this->assertSame( array(), get_post_meta( $post_id, 'subtitle', false ), 'null deletes' );

		wp_set_current_user( self::$contributor );
		$this->fails(
			'kuduy-post-meta-set',
			array(
				'post_id' => $post_id,
				'key'     => 'subtitle',
				'value'   => 'x',
			),
			'contributor cannot edit others\' meta'
		);
	}
}
